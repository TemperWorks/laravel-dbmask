#### DBMask

Experimental package for Dynamic Database Masking.

#### Installation

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
