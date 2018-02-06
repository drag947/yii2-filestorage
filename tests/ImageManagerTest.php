<?php
/**
 * Файл класса ImageManagerTest
 *
 * @copyright Copyright (c) 2017, Oleg Chulakov Studio
 * @link http://chulakov.com/
 */

namespace chulakov\filestorage\tests;

use chulakov\filestorage\image\Position;
use chulakov\filestorage\ImageComponent;
use chulakov\filestorage\managers\ImageManager;
use chulakov\filestorage\uploaders\UploadedFile;

/**
 * Class ImageManagerTest
 * @package chulakov\filestorage\tests
 */
class ImageManagerTest extends TestCase
{
    /**
     * @inheritdoc
     */
    protected function setUp()
    {
        parent::setUp();
        $this->mockApplication();
    }

    /**
     * Инициализация ImageManager
     */
    public function testInitManager()
    {
        $imageManager = new ImageManager();
        $this->assertInstanceOf(ImageManager::className(), $imageManager);
    }

    /**
     * Получить список слушателей
     *
     * @return array
     */
    protected function getListener()
    {
        return ['listeners' =>
            [
                [
                    'class' => ImageManager::className(),
                    'width' => 640,
                    'height' => 480,
                    'encode' => 'jpg',
                    'quality' => 100, // в процентах
                    'watermarkPath' => \Yii::getAlias('@tests/data') . '/images/watermark/watermark.png',
                    'watermarkPosition' => Position::CENTER,
                    'imageComponent' => 'imageComponent'
                ]
            ]
        ];
    }

    /**
     * Эмулирование работы ImageManager
     *
     * @throws \yii\base\InvalidConfigException
     */
    public function testImageManage()
    {
        // Путь к сохраняемому файлу
        $path = \Yii::getAlias('@tests/runtime') . '/images/image.jpg';
        /** @var UploadedFile $uploader */
        $uploader = UploadedFile::getInstance(new ImageModelTest(), 'imageManager');
        // Конфигурирование слушателей
        $uploader->configure($this->getListener());
        // Установить системное имя
        $uploader->setSysName('image');
        // Сохранение файла
        $uploader->saveAs($path, false);

        $this->assertInstanceOf(UploadedFile::className(), $uploader);
        $this->assertEquals(filesize($path), $uploader->getSize());
        $this->assertEquals(basename($path), $uploader->getName());
        $this->assertEquals(mime_content_type($path), $uploader->getType());
        // Удаление сгенерированного файла
        unlink($path);
    }
}