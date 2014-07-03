# phergie/phergie-irc-plugin-react-youtube

[Phergie](http://github.com/phergie/phergie-irc-bot-react/) plugin for providing information about YouTube videos.

[![Build Status](https://secure.travis-ci.org/phergie/phergie-irc-plugin-react-youtube.png?branch=master)](http://travis-ci.org/phergie/phergie-irc-plugin-react-youtube)

## Install

The recommended method of installation is [through composer](http://getcomposer.org).

```JSON
{
    "require": {
        "phergie/phergie-irc-plugin-react-youtube": "dev-master"
    }
}
```

See Phergie documentation for more information on
[installing and enabling plugins](https://github.com/phergie/phergie-irc-bot-react/wiki/Usage#plugins).

## Configuration

```php
return array(

    'plugins' => array(

        // dependencies
        new \WyriHaximus\Phergie\Plugin\Dns\Plugin,
        new \WyriHaximus\Phergie\Plugin\Http\Plugin,
        new \WyriHaximus\Phergie\Plugin\Url\Plugin,

        new \Phergie\Irc\Plugin\React\YouTube\Plugin(array(

            // required: Google API key
            'key' => 'YOUR_KEY_GOES_HERE',

            // optional: pattern used to format video data before sending it
            'responseFormat' =>
                    '[ %link% ] "%title%" by %author%'
                    . '; Length %duration%'
                    . '; Published %published%'
                    . '; Views %views%'
                    . '; Likes %likes%',

            // optional: date format used for video publish timestamps
            'publishedFormat' => 'n/j/y g:i A',

            // optional: interval format used for video durations
            'durationFormat' => '%im%ss',

        )),

    )
);
```

Markers supported in `responseFormat`:
* `%link%`
* `%title%`
* `%author%`
* `%published%`
* `%views%`
* `%likes%`
* `%dislikes%`
* `%favorites%`
* `%comments%`
* `%duration%`

[How to get a Google API key](https://developers.google.com/youtube/v3/getting-started#before-you-start)

[Format used by `publishedFormat`](http://php.net/manual/en/function.date.php#refsect1-function.date-parameters)

[Format used by `durationFormat`](http://php.net/manual/en/dateinterval.format.php#refsect1-dateinterval.format-parameters)

## Tests

To run the unit test suite:

```
curl -s https://getcomposer.org/installer | php
php composer.phar install
cd tests
../vendor/bin/phpunit
```

## License

Released under the BSD License. See `LICENSE`.
