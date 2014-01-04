<?
include("jsonselect.php");

$data = json_decode('{
    "name": {
        "first": "Lloyd",
        "last": "Hilaiel"
    },
    "favoriteColor": "yellow",
    "languagesSpoken": [
        {
            "lang": "Bulgarian",
            "level": "advanced"
        },
        {
            "lang": "English",
            "level": "native",
            "preferred": true
        },
        {
            "lang": "Spanish",
            "level": "beginner"
        }
    ],
    "seatingPreference": [
        "window",
        "aisle"
    ],
    "drinkPreference": [
        "whiskey",
        "beer",
        "wine"
    ],
    "weight": 172
}');


$expr = ".languagesSpoken :has(.lang:val(\"English\"))";


print_r( (new JSONSelect($expr))->match($data) );


