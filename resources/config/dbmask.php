<?php declare(strict_types=1);

use TemperWorks\DBMask\DBMask;

return [
    'masking' => [
        'source' => 'mysql',
        'target' => 'mysql_masking',
    ],
    'materializing' => [
        'source' => 'mysql',
        'target' => 'mysql_materializing',
    ],

    'auto_include_pks' => false,
    'auto_include_timestamps' => ['created_at', 'updated_at'],

    'tables' => [
        'users' => [
            'email' => "concat(first_name,'@example.com')",
            'first_name',
            'last_name' => DBMask::random('last_name', 'english_last_names'),
        ]
    ],

    'mask_datasets' => [
        'last_name' => ['Smith','Johnson','Williams','Jones','Brown','Davis','Miller','Wilson','Moore','Taylor','Anderson','Thomas','Jackson','White','Harris','Martin','Thompson','Garcia','Martinez','Robinson','Clark','Rodriguez','Lewis','Lee','Walker','Hall','Allen','Young','Hernandez','King','Wright','Lopez','Hill','Scott','Green','Adams','Baker','Gonzalez','Nelson','Carter','Mitchell','Perez','Roberts','Turner','Phillips','Campbell','Parker','Evans','Edwards','Collins','Stewart','Sanchez','Morris','Rogers','Reed','Cook','Morgan','Bell','Murphy','Bailey','Rivera','Cooper','Richardson','Cox','Howard','Ward','Torres','Peterson','Gray','Ramirez','James','Watson','Brooks','Kelly','Sanders','Price','Bennett','Wood','Barnes','Ross','Henderson','Coleman','Jenkins','Perry','Powell','Long','Patterson','Hughes','Flores','Washington','Butler','Simmons','Foster','Gonzales','Bryant','Alexander','Russell','Griffin','Diaz','Hayes']
    ]
];
