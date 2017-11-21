## DBMask

An experimental Laravel Package for whitelisted Dynamic Database Masking.

**DISCLAIMER: This package is currently considered to be in an Alpha state. There are no guarantees it will work correctly. Be sure to verify and audit the code yourself before using it, and create backups to guarantee the safety of your data.**

Feedback and contributions are welcome.

## Installation

Install with composer:
```
composer update temperworks/laravel-dbmask
```

You might need to add the repository:
```json
{
    "repositories": [{
        "type": "vcs",
        "url": "https://github.com/TemperWorks/laravel-dbmask"
    }],
    "require": {
        "temperworks/laravel-dbmask": "dev-master",
    }
}
```

Publish a sample config:
```
php artisan vendor:publish --tag=dbmask
```

## Usage

#### About this package

As an example, lets take a production database with just a users table:

```mysql
create table users
(
  id                  int(10) unsigned auto_increment primary key,
  email               varchar(255) not null,
  first_name          varchar(255) null,
  last_name           varchar(255) null,
  password            varchar(60) null,
  social_security_num varchar(20) null,
  birth_date          date null,
  favorite_drink      varchar(20) null
)
```

A data scientist might request a copy of this production database, and they might be interested in aggregates such as `favorite_drink` per age group. The problem is that by granting access to the production database, you'll also give them access to database columns containing `social_security_number` and (a hopefully hashed) `password` string.

This is undesirable, as it increases the risk of personal data leaks.

This can be remedied by creating a schema `anonimized`, granting access to the right people, and adding views with explicitly whitelisted columns as follows:

```mysql
create view anonimized.users as
  select id, birthdate, favorite_drink
  from production.users
```

This view behaves a lot like a filtered table. All select queries to the view pass through the `select` filter, onwards to the production database. 

Writing hundreds of `create view` statements, and running them manually each time you update your database isn't feasible though, and that's where this package offers a solution.

#### Live masked views & Materialized indexed masked views

This package generates all the queries for you.

After publishing the config, create the desired schemas in MySQL, edit the config, and run:

```
artisan db:mask
artisan db:materialize
```

The `dbmask.php` config specifies the following config keys:

|key|type|description|
|---|---|---|
|`tables`|`[]`|An array of tables with their columns. Omitted tables will not be included in the schema, omitted columns will not be included in the views.|
|`table_filters`|`[]`|Which rows to filter|
|`masked_schema`|`string`|The schema name where the masked views will be stored, such as 'anonimized'|
|`materialized`|`string`|The schema name where the materialized masked views will be stored, such as 'materialized'|
|`auto_include_fks`| `bool`| whether to include foreign keys by default|
|`auto_include_pks`| `bool`| whether to include primary keys by default|
|`auto_include_timestamps`| `bool` or `[]`| whether to include timestamps by default, optionally with array of column names|
|`connection`| `string`| Which DB connection to use, if it's not the default one |
|`mask_datasets`|`[]`| Datasets which can be used for randomized masking |

The `tables` array includes all the column transformations:
```php
'users' => [
    // id will be included by default if auto_include_pks is true
    'id',
    // MySQL functions can be used to mask data
    'email' => "concat(md5('id'),'@example.com')",
    // A non-associative array value will be included unmasked
    'birth_date',
    // Sets the column to null
    'first_name' => 'null'
    // Picks a semi-random English last name from a dataset, 
    // using the last_name column as a hash seed
    'last_name' => DBMask::random('last_name', 'english_last_names'),
    // Uses the application key to generate a password hash 
    'password' => DBMask::bcrypt('secret'),
]
```

The `table_filters` array can optionally include rules for masking whole rows: 

```php
// exclude records of people born after 2000
'users' => 'birth_date < 2000-01-01',
// include table as view, but truncate to zero records
'audit_log' => 'false'
```

It is possible to define custom randomization sets, for example using the [Faker](https://github.com/fzaninotto/Faker) package:

```php
$faker = Faker\Factory::create('nl_NL');

'tables' => [
    'users' => [
        'social_security_num' => DBMask::random('social_security_num', 'ssn')
    ]
]

'mask_datasets' => [
    // generates a set of form-validatable social security numbers
    'ssn' => DBMask::generate(100, function() use ($faker) {
        return $faker->unique()->idNumber;
    }),
]
```
