<?php

namespace NW\WebService\References\Operations\Notification\Controllers;

abstract class ReferencesOperation
{
    abstract public function doOperation(): array;

    abstract public function getRequestData($pName): array;
}