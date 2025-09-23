<?php

return [
    // Общие ответы
    'forbidden'           => 'Доступ запрещён',
    'not_available'       => 'Объект недоступен',
    'deleted_marked'      => 'Объект помечен как удалён',
    'updated_ok'          => 'Обновлено успешно',

    // Карта / bbox
    'invalid_bbox'        => 'Некорректный bbox. Ожидается строка вида south,west,north,east',
    'zoom_out_of_range'   => 'Параметр zoom должен быть в диапазоне :min–:max.',

    // Валидация полей (кастомные уточнения)
    'latitude_between'    => 'Широта должна быть между -90 и 90.',
    'longitude_between'   => 'Долгота должна быть между -180 и 180.',
    'youtube_url'         => 'Ссылка на YouTube должна быть корректным URL.',
    'photos_limit'        => 'Нельзя загрузить более :max фотографий.',
    'photo_file_invalid'  => 'Каждый файл фото должен быть изображением формата jpg, jpeg, png или webp размером до :max КБ.',
    'photo_positions_int' => 'Позиции фотографий должны быть целыми числами.',
    'photo_order_exists'  => ' photo_order содержит неизвестный идентификатор фото.',
    'delete_photo_exists' => ' delete_photo_ids содержит неизвестный идентификатор фото.',
];
