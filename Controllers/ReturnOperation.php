<?php
namespace NW\WebService\References\Operations\Notification\Controllers;

use NW\WebService\References\Operations\Notification\Models\Contractor;
use NW\WebService\References\Operations\Notification\Models\Employee;
use NW\WebService\References\Operations\Notification\Models\NotificationEvents;
use NW\WebService\References\Operations\Notification\Models\Seller;
use NW\WebService\References\Operations\Notification\Models\Status;

/**
 * Класс предназначен для выполнения операции возврата товара (TsReturnOperation)
 * и отправки уведомлений сотрудникам и клиентам о статусе этой операции.
 * Код проверяет входные данные, извлекает информацию о пользователях и клиенте,
 * формирует шаблон данных и отправляет уведомления по электронной почте и SMS.
 * Основная логика включает обработку типа уведомления (новое или изменение),
 * проверку корректности данных и отправку сообщений.
 */
class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws \Exception
     */
    public function doOperation(): array
    {
        $data = $this->getRequestData('data');
        $resellerId = (int)$data['resellerId'];
        $notificationType = (int)$data['notificationType'];

        $this->validateData($resellerId, $notificationType, $data);

        $reseller = Seller::getById($resellerId);
        $this->checkEntityExistence($reseller, 'Seller not found!', 400);

        $client = Contractor::getById((int)$data['clientId']);
        $this->validateClient($client, $resellerId);

        $creator = Employee::getById((int)$data['creatorId']);
        $this->checkEntityExistence($creator, 'Creator not found!', 400);

        $expert = Employee::getById((int)$data['expertId']);
        $this->checkEntityExistence($expert, 'Expert not found!', 400);

        $differences = $this->getDifferencesMessage($notificationType, $data, $resellerId);
        $templateData = $this->prepareTemplateData($data, $creator, $expert, $client, $differences);

        $this->validateTemplateData($templateData);

        $result = $this->sendNotifications($reseller, $client, $templateData, $notificationType, $data);

        return $result;
    }

    public function getRequestData($pName): array
    {
        return (array)$_REQUEST[$pName];
    }

    private function validateData($resellerId, $notificationType, $data): void
    {
        if (empty($resellerId)) {
            throw new \InvalidArgumentException('Empty resellerId', 400);
        }

        if (empty($notificationType)) {
            throw new \InvalidArgumentException('Empty notificationType', 400);
        }

        if (empty($data['clientId']) || empty($data['creatorId']) || empty($data['expertId'])) {
            throw new \InvalidArgumentException('Missing required data', 400);
        }
    }

    private function checkEntityExistence($entity, $errorMessage, $errorCode): void
    {
        if (empty($entity)) {
            throw new \RuntimeException($errorMessage, $errorCode);
        }
    }

    private function validateClient($client, $resellerId): void
    {
        if (empty($client) || $client->type !== Contractor::TYPE_CUSTOMER || $client->id !== $resellerId) {
            throw new \RuntimeException('Client not found or mismatch!', 400);
        }
    }

    private function getDifferencesMessage($notificationType, $data, $resellerId): string
    {
        if ($notificationType === self::TYPE_NEW) {
            return __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            return __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO' => Status::getName((int)$data['differences']['to']),
            ], $resellerId);
        }
        return '';
    }

    private function prepareTemplateData($data, $creator, $expert, $client, $differences): array
    {
        return [
            'COMPLAINT_ID' => (int)$data['complaintId'],
            'COMPLAINT_NUMBER' => (string)$data['complaintNumber'],
            'CREATOR_ID' => (int)$data['creatorId'],
            'CREATOR_NAME' => $creator->getFullName(),
            'EXPERT_ID' => (int)$data['expertId'],
            'EXPERT_NAME' => $expert->getFullName(),
            'CLIENT_ID' => (int)$data['clientId'],
            'CLIENT_NAME' => $client->getFullName() ?: $client->name,
            'CONSUMPTION_ID' => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER' => (string)$data['agreementNumber'],
            'DATE' => (string)$data['date'],
            'DIFFERENCES' => $differences,
        ];
    }

    private function validateTemplateData($templateData): void
    {
        foreach ($templateData as $key => $value) {
            if (empty($value)) {
                throw new \RuntimeException("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    private function sendNotifications($reseller, $client, $templateData, $notificationType, $data): array
    {
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];

        $emailFrom = $reseller->getResellerEmailFrom($reseller->id);
        $emails = $reseller->getEmailsByPermit($reseller->id, 'tsGoodsReturn');

        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $email,
                        'subject' => __('complaintEmployeeEmailSubject', $templateData, $reseller->id),
                        'message' => __('complaintEmployeeEmailBody', $templateData, $reseller->id),
                    ],
                ], $reseller->id, NotificationEvents::CHANGE_RETURN_STATUS);

                $result['notificationEmployeeByEmail'] = true;
            }
        }

        if ($notificationType === self::TYPE_CHANGE && !empty($data['differences']['to'])) {
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $client->email,
                        'subject' => __('complaintClientEmailSubject', $templateData, $reseller->id),
                        'message' => __('complaintClientEmailBody', $templateData, $reseller->id),
                    ],
                ], $reseller->id, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);

                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $res = NotificationManager::send($reseller->id, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);

                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }

                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
