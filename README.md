![SkyPHP - A lightweight PHP5 framework for building scalable HTML5 websites](https://raw.github.com/will123195/skyphp/3.0/pages/default/skyphp-banner.png)

## System Requirements

- A web server (tested with Apache 2)
- PHP 5.4
- PHP PDO module (optional) -- for database support
- PHP Memcache module (optional) -- for memcached support
- Git (optional) -- for easy upgrades

## Features

- PHP-FIG Standards Compliant [http://www.php-fig.org/]
- `Sky\Page` organizes your webpages and URLs
    - Database folders - define your urls using database rows
    - Queryfolders - make your querystrings pretty
    - Templates
        - Auto-minify and bundle CSS/JS (SASS coming soon)
        - Ajax navigation
    - Inherit folder (virtual symlink)
- `Sky\Model` is an ORM that uses AQL
- `Sky\Api` is a framework for creating REST APIs
- `Sky\Db` supports Master/Slave DB environments (PostgreSQL 9.1 & MySQL)
- `Sky\Memcache` supports redundant Memcached servers
- Cascading codebases and hooks
- CMS add-on codebase available [https://github.com/SkyPHP/cms]

## Install

1. Let's assume you are setting up a website called **mysite.com** and it has a public web
folder called `public_html`.

1. Create a folder called `codebases`.  **Do not** put it inside `public_html`.
```bash
mkdir /path/to/codebases
```

1. Create a folder called `storage`.  **Do not** put it inside `public_html`.  You
need to set the permissions so your web server can write to this folder.
```bash
mkdir /path/to/storage
chmod 777 /path/to/storage
```

1. Clone the SkyPHP codebase into your `codebases` folder.
```bash
cd /path/to/codebases
git clone --recursive -b 3.0 git://github.com/SkyPHP/skyphp.git
```

1. Create a folder for your new project in `codebases`.
```bash
mkdir /path/to/codebases/my-project
mkdir /path/to/codebases/my-project/lib
mkdir /path/to/codebases/my-project/pages
mkdir /path/to/codebases/my-project/templates
```

1. Put `.htaccess` and `index.php` into your public web folder:

- **/path/to/public_html/.htaccess** (copy this file from `skyphp/`, or better yet create a symbolic link)

    ```bash
    ln -s /path/to/codebases/skyphp/.htaccess /path/to/public_html/
    ```

- **/path/to/public_html/index.php**

    ```php
    <?php
    // index.php
    // Powered by SkyPHP (github.com/SkyPHP)

    #$down_for_maintenance = true;

    $my_project_path = '/path/to/codebases/my-project/';
    $skyphp_codebase_path = '/path/to/codebases/skyphp/';

    $codebase_path_arr = [
        $my_project_path,
        $skyphp_codebase_path
    ];

    // make sure this folder is writable
    $skyphp_storage_path = "/path/to/storage/";

    include $skyphp_codebase_path . 'sky.php';
    ```

Now open **mysite.com** in your browser and you should see the 'Welcome to SkyPHP' page.


## Documentation

### `Sky\Page`

#### Sample page
`/pages/test/test.php` --> example.com/test
```php
<?php

$this->title = 'Hello World';

// The js and css files are attached automatically by the html5 template
// $this->js[] = '/pages/test/test.js';
// $this->css[] = '/pages/test/test.css';

$this->js[] = '/lib/js/some-other-library.js';

$this->head[] = '<meta property="example" content="you can add html to your head">';

$this->template('html5', 'top');
?>
    <h1>My Hello World Page</h1>
<?
    // ($this) is the Page object
    d($this); // see some info about your Page

$this->template('html5', 'bottom');
```

### `Sky\Model`

#### Saving data objects to the database
```php
<?php

use \My\Models\artist;

$artist = new artist([                  // create the object
    'name' => 'Anthrax'
]);                                     // but don't save to the database yet

if ($artist->_errors) {                 // validation errors are generated in real-time
    d($artist->_errors);                // dump _errors array in a nice html format
}

$artist->name = 'Slayer';               // set the artist's name
$artist->save();                        // save the object to the database

$artist->set([                          // change multiple fields
    'name' => 'Slayer',                 // *name is not saved in this case because only
    'state' => 'CA',                    // modified fields are saved
    'albums' => [                       // you can easily save nested objects
        [
            'name' => 'Diabolus in Musica',
            'year' => 1998
        ]
    ]
])->save();

$artist->update([                       // shorthand for updating fields in the database
    'name' => 'Slanthrax'
]);

$artist = artist::insert([              // shorthand for inserting new objects to the db
    'name' => 'Anthrax',
    'state' => 'NY'
]);
echo $artist->id; // 5                  // get the newly created artist_id
```

#### Getting data objects from the database
```php
<?php

$aritst_id = 5;
$artist = new artist($artist_id);       // get artist from database by primary key
$artist = artist::get($artist_id);      // this is the same as above
echo $artist->name; // Anthrax

$artist = artist::getOne([              // get one artist using some criteria
    'where' => "name = 'Slayer'"        // ('where' can be a string or array)
])->update([                            // and change their city
    'city' => 'Huntington Park'
]);

$artists = artist::getMany([            // get 100 "the" bands from Brooklyn
    'where' => [
        "name ilike 'The %'",
        "city = 'Brooklyn'",
        "state = 'NY'"
    ],
    'limit' => 100
]);
foreach ($artists as $artist) {         // display each artist and number of albums
    $qty = count($artist->albums);
    echo "{$artist->name} have {$qty} albums.<br />";
}

$number_of_bands = artist::getCount([   // get a count of artists in Brooklyn
    'where' => "city = 'Brooklyn' and state = 'NY'"
]);

$artist_ids = artist::getList([         // get an array of every artist.id in NY
    'where' => "state = 'NY'"
]);
```

#### Sample Model
`/lib/My/Models/artist.php`
```php
<?php

namespace Crave\Models;

class artist extends \Sky\Model
{
    const AQL = "
        artist {
            name,
            city,
            state,
            [artist_type],
            [artist_genre]s as genres
        }
        artist_user {
            [person]
        }
    ";

    /**
     *
     */
    public static $_meta = [

        'possibleErrors' => [
            'invalid_state' => [
                'message' => 'Please enter a valid two character state abbreviation.',
                'type' => 'invalid',
                'fields' => ['state']
            ]
        ],

        'requiredFields' => [
            'name' => 'Artist Name'
        ],

        'readOnlyProperties' => [
            'artist_type'
        ],

        'cachedLists' => [
            'artist_type_id'
        ]
    ];

    /**
     * Validates 'state' field only if $this->state is not null
     */
    public function validate_state()
    {
        if (strlen($this->state) != 2) {
            $this->addError('invalid_state');
        }
    }

    /**
     *
     */
    public function validate()
    {

    }

}
```
