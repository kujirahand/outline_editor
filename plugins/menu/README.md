# Menu plugins

Place `*.inc.php` files here to add items to the hamburger menu.

For `example.inc.php`, define:

```php
<?php

function get_menu_example(array $args): array
{
    return [
        'label' => 'Sample',
        'html' => '<p>Subpage HTML</p>',
    ];
}
```

To handle plugin API calls for the same file, define:

```php
function exec_example(array $params): array
{
    return ['label' => 'Result'];
}
```
