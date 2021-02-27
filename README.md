# Протокол обмена 1С с сайтом Bitrix для Yii2

В библиотеке реализована серверная часть протокола для обработки запросов от 1С, а также клиентская часть, которая
эмулирует запросы 1С к серверу сайта.

## Серверная часть

Состоит из:

- настраиваемого модуля `dicr\exchange1c\Module`
- web-контроллера `dicr\exchane1c\DefaultController` для обработки запросов от 1С
- абстрактного обработчика протокола `dicr\exchange1c\BaseHandler` который реализует базовые функции.

### Настройка серверной части

```php
$config = [
    'modules' => [
        'exchange1c' => dicr\exchange1c\Module::class,
        'handler' => 'ВашОбработчикИмпорта::class',
        
        // опционально можно добавить авторизацию
        'as basicAuth' => [
            'class' => yii\filters\auth\HttpBasicAuth::class,
            'auth' => static function($username, $password) {
                // проверка логина и пароля
            }
        ]
    ]
];
```

Обработчик обмена с 1С, вызываемый модулем должен реализовывать интерфейс `dicr\exchange1c\Handler`. Для удобства вы
можете наследовать абстрактны базовый класс `dicr\exchange1c\BaseHandler` в котором реализованы функции обмена и
утилиты. Вам необходимо только переопределить методы импорта данных (`importProp`, `importGroup`, `importProd`)
из документа `SimpleXmlElement` в базу своего сайта.

## Клиентская часть

Состоит из:

- настраиваемого компонента `dicr\exchange1c\Client`
- консольного приложения и контроллера `dicr\exchange1c\ClientController`

### Настройка клиентской части

```php
$config = [
    'components' => [
        'client' => [
            'class' => dicr\exchange1c\Client::class,
            'url' => 'https://адрес_обмена/сайта',
            
            // опционально авторизация на сайте
            'login' => 'логин',
            'password' => 'пароль'
        ]
    ]       
];
```

### Использование

```php
/** @var dicr\exchange1c\Client $client */
$client = Yii::$app->get('client');

// авторизация (получает куку авторизации)
$client->requestCatalogCheckAuth();

// инициализация параметров обмена (получает zip, file_limit)
$client->requestCatalogInit();

// загрузка файла на сайт
$data = $client->requestCatalogFile('/home/files/import.xml');

// импорт данных
$client->requestCatalogImport('import.xml');
```

### Консольное приложение

Использовать клиентскую часть можно также в консоли.

Для настроек создать файл `configs/local.php` с данными:

```php
/** @var ?string адрес обмена на сайте */
const EXCHANGE_URL = 'https://мой-сайт.рф/sync1c';

/** @var ?string логин */
const EXCHANGE_LOGIN = 'мой-логин';

/** @var ?string пароль */
const EXCHANGE_PASSWORD = 'мой пароль';
```

Аргументы командной строки:

```shell
# отправка каталога на сайт
./yii client/catalog-file /home/files/import.xml

# отправка заказов на сайт
./yii client/sale-file /home/files/orders.xml

# запрос заказов с сайта
./yii client/sale-query
```
