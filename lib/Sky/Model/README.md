```php
<?php

class artist extends \Sky\Model
{
    const AQL = "
        artist {
            name,
            genre,
            [album]s as albums
        }
        city {
            name as city
        }
    ";
}

class album extends \Sky\Model
{
    const AQL = "
        album {
            name,
            year
        }
    ";
}

$data = [
    'name' => 'Nirvana',
    'genre' => 'Grunge',
    'albums' => [
        [
            'name' => 'Nevermind',
            'year' => 1991
        ]
    ]
];

################################################
#   How to save a new artist to the database   #
################################################

// Example 1
$aritst = artist::insert($data);

// Example 2
$artist->name = 'Pearl Jam';
$artist->albums[0]->name = 'Ten';
$artist->save();
if (!$artist->getErrors()) {
    echo 'Saved successfully.'
} else {
    print_r($artist->getErrors());
}

// Example 3
$artist = new artist();
$artist->set(array(
    'name' => 'Metallica',
    'genre' => 'Metal'
));
$artist->runValidation();
if ($artist->getErrors()) {
    echo 'There are validation errors. Save will not be successful.'
}


```
