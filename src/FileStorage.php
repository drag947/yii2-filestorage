<?php
/**
 * Файл класса FileStorage
 *
 * @copyright Copyright (c) 2017, Oleg Chulakov Studio
 * @link http://chulakov.com/
 */

namespace chulakov\filestorage;

use Yii;
use yii\rbac\Item;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\base\UnknownClassException;
use yii\helpers\Url;
use yii\helpers\FileHelper;
use chulakov\filestorage\models\BaseFile;
use chulakov\filestorage\uploaders\UploadInterface;
use chulakov\filestorage\services\PathService;
use chulakov\filestorage\services\FileService;
use chulakov\filestorage\exceptions\NotUploadFileException;
use chulakov\filestorage\exceptions\NotFoundFileException;
use chulakov\filestorage\exceptions\NoAccessException;
use chulakov\filestorage\params\UploadParams;

/**
 * Class FileStorage
 * @package chulakov\filestorage
 */
class FileStorage extends Component
{
    /**
     * Базовый URL, который будет подставляться при генерации url к файлу.
     * Если false, то будет использован текущий хост при генерации абсолютных URL-адресов
     *
     * @var string|false
     */
    public $storageBaseUrl = false;
    /**
     * Базовый путь к доступной из web директории,
     * в которой будет размещаться директория для хранения файлов [[$storageDir]]
     *
     * @var string
     */
    public $storagePath = '@webroot';
    /**
     * Наименование директории для хранения файлов
     *
     * @var string
     */
    public $storageDir = 'upload';
    /**
     * Патерн генерации пути сохранения файлов
     * Допустимые токены:
     *  {id} - Подставляет в путь идентифиатор модели, для которой загружается файл (object_id)
     *  {group} - Подставляет в путь группу файлов (group_code)
     *
     * @var string
     */
    public $storagePattern = '{group}/{id}';
    /**
     * Если заданы права, то после создания файла они будут принудительно назначены
     *
     * @var number|null
     */
    public $fileMode = 0775;
    /**
     * @var FileService
     */
    protected $service;
    /**
     * @var PathService
     */
    protected $pathService;

    /**
     * Конструктор с зависимостью от сервиса
     *
     * @param FileService $service
     * @param array $config
     */
    public function __construct(FileService $service, array $config = [])
    {
        $this->service = $service;
        parent::__construct($config);
    }

    /**
     * При инициализации проверяем необходимые конфигурационные переменные
     *
     * @throws InvalidConfigException
     */
    public function init()
    {
        if (is_null($this->storageBaseUrl) || ($this->storageBaseUrl !== false && trim($this->storageBaseUrl) === '')) {
            throw new InvalidConfigException("Параметр 'storageBaseUrl' имеет неверное значение");
        }

        if (!$this->storagePath) {
            throw new InvalidConfigException("Параметр 'storagePath' должен быть указан");
        }

        if (!$this->storageDir) {
            throw new InvalidConfigException("Параметр 'storageDir' должен быть указан");
        }
        $this->pathService = new PathService($this->storagePath, $this->storageDir);
    }

    /**
     * Загрузка файла
     *
     * @param UploadInterface|UploadInterface[] $files
     * @param UploadParams $params
     * @return array|BaseFile|null
     * @throws NoAccessException
     * @throws NotUploadFileException
     */
    public function uploadFile($files, UploadParams $params)
    {
        $this->canAccess($params->accessRole);

        /** @var BaseFile[] $result */
        $result = [];
        if ($single = !is_array($files)) {
            $files = [$files];
        }
        try {
            /** @var array $files */
            foreach ($files as $file) {
                $result[] = $this->saveFile($file, $params);
            }
        } catch (\Exception $e) {
            $this->clearFiles($files, $e);
            $this->clearModel($result);
            throw new NotUploadFileException('Не удалось сохранить файл', 0, $e);
        }
        return $single ? array_shift($result) : $result;
    }

    /**
     * Проверка прав доступа к файлу
     *
     * @param Item $role
     * @param BaseFile $model
     * @return bool
     * @throws NoAccessException
     */
    protected function canAccess($role = null, $model = null)
    {
        if (empty($role)) {
            return true;
        }
        $params = [];
        if (!is_null($model)) {
            $params['file'] = $model;
        }
        if (\Yii::$app->user->can($role, $params)) {
            return true;
        }

        throw new NoAccessException('Нет прав доступа не сохранение файла.');
    }

    /**
     * Сохранение файла и создание для него модели с мета данными
     *
     * @param UploadInterface $file
     * @param UploadParams $params
     * @return BaseFile|null
     * @throws \yii\base\InvalidParamException
     * @throws NotUploadFileException
     * @throws \Exception
     */
    protected function saveFile(UploadInterface $file, UploadParams $params)
    {
        // Генерация всех необходимых частей для сохранения файла
        $path = $this->getSavePath($params);
        $full = $this->getAbsolutePath($path);

        // Сохранение файла и создание модели с данными о файле
        $file->saveAs($full . DIRECTORY_SEPARATOR . $file->getSysName());
        if ($model = $this->createModel($file, $params)) {
            $model->setSystemFile($file->getSysName(), $path);
            if ($this->service->save($model)) {
                return $model;
            }
        }
        throw new NotUploadFileException('Не удалось сохранить данные о файле: ' . $file->getBaseName());
    }

    /**
     * Формирование относительного пути с учетом настроек и переданных параметров
     *
     * @param UploadParams $params
     * @return string
     */
    protected function getSavePath(UploadParams $params)
    {
        return str_replace(['\\', '\/'], DIRECTORY_SEPARATOR,
            implode('/', array_filter([
                $this->storageDir, trim(strtr($this->storagePattern, [
                    '{id}' => $params->object_id,
                    '{group}' => $params->group_code,
                ]), '\\\/')
            ]))
        );
    }

    /**
     * Формирование абсолютного пути до файлов с созданием новой директории, если ее еще не существует
     *
     * @param string $path
     * @return string
     * @throws \yii\base\InvalidParamException
     * @throws NotUploadFileException
     */
    protected function getAbsolutePath($path)
    {
        $full = FileHelper::normalizePath(
            Yii::getAlias($this->storagePath) . '/' . $path
        );
        if (!$this->checkPath($full)) {
            throw new NotUploadFileException('Нет доступа к каталогу для сохранения файла.');
        }
        return $full;
    }

    /**
     * Проверка наличия директории с попыткой создать новую, если это возможно
     *
     * @param string $full
     * @return boolean
     */
    protected function checkPath($full)
    {
        if (!is_dir($full)) {
            try {
                return FileHelper::createDirectory($full, $this->fileMode);
            } catch (Exception $e) {
                return false;
            }
        }
        return true;
    }

    /**
     * Создание модели мета информации о файле
     *
     * @param UploadInterface $file
     * @param UploadParams $params
     * @return BaseFile|null
     */
    protected function createModel(UploadInterface $file, UploadParams $params)
    {
        try {
            if (strpos($file->getType(), 'image') !== false) {
                return $this->service->createImage($file, $params);
            }
            return $this->service->createFile($file, $params);
        } catch (UnknownClassException $e) {
            return null;
        }
    }

    /**
     * Удаление модели и сохраненого файла
     *
     * @param BaseFile $model
     * @throws \Exception
     * @throws \Throwable
     */
    public function removeFile($model)
    {
        $full = Yii::getAlias($this->storagePath) . '/' . $model->sys_file;
        if (is_file($full)) {
            unlink($full);
        }
    }

    /**
     * Зачистка моделей
     *
     * @param BaseFile[] $models
     */
    protected function clearModel($models)
    {
        foreach ($models as $model) {
            try {
                $model->delete();
            } catch (\Exception $e) {
                Yii::error($e);
            } catch (\Throwable $t) {
                Yii::error($t);
            }
        }
    }

    /**
     * Зачистка файлов
     *
     * @param UploadInterface[] $files
     * @param \Exception $e
     */
    protected function clearFiles($files, \Exception $e)
    {
        foreach ($files as $file) {
            try {
                // todo: обработать событие удаление для всех $files
                // $file->triggerClearEvent($e);
            } catch (\Exception $e) {
                Yii::error($e);
            }
        }
    }

    /**
     * Возвращает абсолютный путь к директории хранения файлов определенного типа
     *
     * @param BaseFile $model
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function getUploadPath($model)
    {
        $params = $this->getParamsFromModel($model);
        $path = FileHelper::normalizePath(implode(DIRECTORY_SEPARATOR, [
            Yii::getAlias($this->storagePath), $this->getSavePath($params)
        ]));
        return $path;
    }

    /**
     * Возвращает URL-адрес до директории нахождения файлов определенного типа
     *
     * @param BaseFile $model
     * @param bool $isAbsolute возвращать абсолютный (полный) URL
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function getUploadUrl($model, $isAbsolute = false)
    {
        $path = $this->getSavePath($this->getParamsFromModel($model));
        return $this->convertToUrl($path, $isAbsolute);
    }

    /**
     * Возвращает полный путь к файлу в файловой системе
     *
     * @param BaseFile $model
     * @param Item $role
     * @return string
     * @throws \yii\base\InvalidParamException
     * @throws NoAccessException
     * @throws NotFoundFileException
     */
    public function getFilePath($model, $role = null)
    {
        $this->canAccess($role, $model);

        if ($path = $this->checkSystemPath($model)) {
            return $path;
        }
        if ($path = $this->checkMovedPath($model)) {
            return $path;
        }
        throw new NotFoundFileException('Не удалось найти файл :' . basename($model->sys_file));
    }

    /**
     * Возвращает абсолютный или относительный URL-адрес к файлу
     *
     * @param BaseFile $model
     * @param bool $isAbsolute
     * @param Item $role
     * @return string
     * @throws \yii\base\InvalidParamException
     * @throws NoAccessException
     */
    public function getFileUrl($model, $isAbsolute = false, $role = null)
    {
        $this->canAccess($role, $model);

        if ($this->checkSystemPath($model)) {
            return $this->convertToUrl($model->sys_file, $isAbsolute);
        }
        $baseName = '/' . basename($model->sys_file);
        if ($this->checkMovedPath($model)) {
            return $this->getUploadUrl($model, $isAbsolute) . $baseName;
        }
        return $this->convertToUrl($baseName, $isAbsolute);
    }

    /**
     * Проверка системного расположения файла
     *
     * @param BaseFile $model
     * @return string|null
     * @throws \yii\base\InvalidParamException
     */
    protected function checkSystemPath($model)
    {
        return $this->checkExistFile(implode(DIRECTORY_SEPARATOR, [
            Yii::getAlias($this->storagePath), $model->sys_file
        ]));
    }

    /**
     * Проверка возможного перемещения файлов по новому шаблону
     *
     * @param BaseFile $model
     * @return string|null
     * @throws \yii\base\InvalidParamException
     */
    protected function checkMovedPath($model)
    {
        return $this->checkExistFile(implode(DIRECTORY_SEPARATOR, [
            $this->getUploadPath($model), basename($model->sys_file)
        ]));
    }

    /**
     * Проверка существования файла
     *
     * @param string $path
     * @return string|null
     */
    protected function checkExistFile($path)
    {
        $path = FileHelper::normalizePath($path);
        if (is_file($path)) {
            return $path;
        }
        return null;
    }

    /**
     * Получение параметров пути из модели
     *
     * @param BaseFile $model
     * @return UploadParams
     */
    protected function getParamsFromModel($model)
    {
        $params = new UploadParams($model->group_code);
        if ($model->object_id) {
            $params->object_id = $model->object_id;
        }
        return $params;
    }

    /**
     * Добавление в URL адрес исходной точки
     *
     * @param string $path
     * @param bool $isAbsolute
     * @return string
     * @throws \yii\base\InvalidParamException
     */
    public function convertToUrl($path, $isAbsolute = false)
    {
        $url = '/' . trim(str_replace('\\', '/', $path), '/');
        if ($this->storageBaseUrl !== false) {
            $url = Url::to($this->storageBaseUrl . $url, true);
        } elseif ($isAbsolute) {
            $url = Url::base(true) . $url;
        }
        return $url;
    }
}
