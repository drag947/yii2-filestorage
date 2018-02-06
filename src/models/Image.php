<?php
/**
 * Файл класса Image
 *
 * @copyright Copyright (c) 2017, Oleg Chulakov Studio
 * @link http://chulakov.com/
 */

namespace chulakov\filestorage\models;

use yii\helpers\ArrayHelper;
use chulakov\filestorage\behaviors\ImageBehavior;

/**
 * Модель представления загруженного файла изображения
 *
 * @method string thumb($w = 195, $h = 144, $q = 80, $p = null)
 *
 * @method string contain($w, $h, $q = 80)          Вписывание изображения в область путем пропорционального масштабирования без обрезки
 * @method string cover($w, $h, $q = 80, $p = null) Заполнение обаласти частью изображения с обрезкой исходного, отталкиваясь от точки позиционировани
 * @method string widen($w, $q = 80)                Масштабирование по ширине без обрезки краев
 * @method string heighten($h, $q = 80)             Масштабирование по высоте без обрезки краев
 *
 * @method bool removeAllThumbs()                   Удаление всех превью данной модели
 * @method bool removeAllImages()                   Удаление всех превью данной мод
 *
 * @package chulakov\filestorage\models
 */
class Image extends BaseFile
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return ArrayHelper::merge(parent::behaviors(), [
            ImageBehavior::className(),
        ]);
    }
}
