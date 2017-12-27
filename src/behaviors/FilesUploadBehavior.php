<?php
/**
 * Файл класса FilesUploadBehavior
 *
 * @copyright Copyright (c) 2017, Oleg Chulakov Studio
 * @link http://chulakov.com/
 */

namespace chulakov\filestorage\behaviors;

use chulakov\filestorage\uploaders\UploadInterface;

/**
 * Class FilesUploadBehavior
 * @package chulakov\filestorage\behaviors
 */
class FilesUploadBehavior extends FileUploadBehavior
{
    /**
     * Проверка, инициализированы ли данные в массиве модели
     *
     * @param mixed $model
     * @return bool
     */
    protected function isInstances($model)
    {
        return !empty($model);
    }

    /**
     * Инициализация массива данных для модели
     *
     * @return mixed
     */
    protected function getInstances()
    {
        $repository = $this->repository;
        $files = $repository::getInstances($this->owner, $this->attribute);
        if (empty($files)) {
            $files = $repository::getInstancesByName($this->attribute);
        }
        return $files;
    }

    /**
     * Конфигурация загруженных файлов
     *
     * @param UploadInterface[] $files
     */
    protected function configureInstances($files)
    {
        if (!empty($this->saveOptions)) {
            foreach ($files as $file) {
                $file->configure($this->saveOptions);
            }
        }
    }
}
