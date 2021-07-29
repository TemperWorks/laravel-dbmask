## DBMask

A Laravel Package for whitelisted Dynamic Database Masking.

Have you ever wondered:

> "How can I share our database with developers and analysts within the company, while also complying with privacy laws like GDPR?

This package might be for you!

To a certain degree, databases seeding scripts can be of help. But sometimes having access to real data or having a recent copy of the database can be essential to many roles within a company, for example to quickly gather real world statistics or to hunt down a bug.

This package can both create a **read-only real-time filter** (Mask through views) and/or a **read/write filtered copy** (Materialized tables) from a MySQL source connection.

To maintain "privacy by default", it works by whitelisting rather than blacklisting data. You explicitly specify which columns you want copied verbatim, which columns you want to anonymize, and which columns (and rows) to completely exclude.

**DISCLAIMER: This package contains code which can delete important data from your database when set up incorrectly. Be sure to verify and audit the code yourself before using it, and create backups to guarantee the safety of your production environment.**

Contributions are always welcome.

## Important Limitations

The following MySQL features can cause issues:

* **Virtual or stored generated columns** can leak data when creating masked views. Use the materialization option if this is an issue.
* **Triggers & UDFs** from the source schema are currently not transferred to the target.
* **Views** from the source schema will be present on the targets, but should rely on masking configuration of underlying tables.

## Installation

Add the repository:
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

Update with composer:
```
composer update temperworks/laravel-dbmask
```

Publish a sample config:
```
php artisan vendor:publish --tag=dbmask
```

### Testing

This package needs a real MySQL database for testing. 
Make sure you use a database which doesn't contain any important data when testing.

Copy `phpunit.xml.dist` to `phpunit.xml`, and change the DB connection variables.  
After that, just run `./vendor/bin/phpunit`.

## Usage

### About this package

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

A data scientist might request a copy of this production database, and they might be interested in aggregates such as `favorite_drink` per age group. The problem is that by granting access to the production database, you'll also give them access to database columns containing `social_security_number` and (hashed) `password` string.

This is undesirable, as it increases the risk of personal data leaks.

A better solution is to provide a copy (or real-time filter) which masks sensitive fields, for example by setting values to `null`, or replacing values with fake data.

### Live masked views & Materialized masked tables

#### Configuration

The `dbmask.php` config specifies the following config keys:

|key|type|description|
|---|---|---|
|`tables`|`[]`|An array of tables with their columns. Omitted tables will not be included in the masked database.|
|`table_filters`|`[]`|Which rows to filter|
|`masking`|`array`|Contains two Database Connection names: a `source` and a `target`|
|`materializing`|`array`|Contains two Database Connection names: a `source` and a `target`|
|`auto_include_fks`| `bool`| whether to include all foreign keys by default|
|`auto_include_pks`| `bool`| whether to include all primary keys by default|
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
    // Picks a stable, semi-random English last name from a dataset, 
    // using the last_name column as a hash seed
    'last_name' => DBMask::random('last_name', 'english_last_names'),
    // Uses the application key to replace the value with a new generated password hash
    // In this case, all users end up with their password set to the bcrypt hashed value of `secret`.
    'password' => DBMask::bcrypt('secret'),
]
```

#### Running the transformations

After publishing and editing the config, create the desired schemas in MySQL, edit the config, and run:

```
php artisan db:mask
and/or
php artisan db:materialize
```

#### Filtering rows

The `table_filters` array can optionally contain rules for excluding specific rows: 

```php
// exclude records of people born after 2000
'users' => 'birth_date < 2000-01-01',
// include the whole table as a valid view, but truncate to zero records
'audit_log' => 'false'
```

#### Fake data providers

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

#### Transferring views from source to target

If views exist on the source schema, these can be transferred to the masked/materialized targets as well.

For example, if the source has a `users_view` which concatenates first & last name into fullname, and exposes a SSN, then define the anonymization config for the table.
Include the view name as well, without any column configuration -- It doesn't have any realy columns after all, it will just point at the anonymized users table.

This works the same when using the live masked anonymization option. In that case, the view from the source will be transferred to the target, where it is layered on top of the masking view. 

```php
'tables' => [
    'users' => [
        'first_name' => "'Jane'"
        'last_name' => "'Doe'"
        'social_security_num' => DBMask::random('social_security_num', 'ssn')
    ],
    
    'users_view' => [],
]
```

There currently are some limitations to using fake seeded data though: 

* It's impossible to guarantee uniqueness with a set which is smaller than the amount of table records
* Very large datasets affect performance negatively in views.
