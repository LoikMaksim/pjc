Возможности
===

  * управление подпиской
  * управление статусом
  * приём/передача сообщений
  * работа с конференциями (без администрирования)
  * поддержка TLS

Примеры
```php
<?php
require_once('pjc/PJC_JabberClient.class.php');

class MyJabberBot extends PJC_JabberClient {
	/*
		Базовое событие
		автоматически вызывается при получении сообщения
	*/
	protected function onMessage($sender, $body, $subject, $stanza) {
		// Отвечаем отправителю
		$sender->sendMessage('Received message: '.$body);

		if($body == '!die') {
			// закрываем соединение и выходим из event loop'а
			$this->disconnect();

			// останавливаем дальнейшую обработку этого сообщения,
			// если на нём весят ещё какие-то хендлеры
			return false;
		}
	}

	/*
		Базовое событие
		Вызывается при получении запроса подписки (авторизации)
	*/
	protected function onSubscribeRequest($sender, $stanza) {
		// принимаем запрос подписки
		$sender->acceptSubscription();

		// запрашиваем подписку для себя
		$sender->requestSubscription();

		$sender->sendMessage('subscribed!');
	}

	/*
		Пресет для крона.
		Вызывается раз в час, время отсчитывается с момента запуска.
		Так же есть сходный пресет daily(), вызывающийся раз в сутки.
		Добавить пользовательское правило в крон можно через
		$this->addPeriodic(
			uint интервал_в_секундах,
			callback коллбек
			[, array параметры_вызова_коллбека = array()]
			[, string идентификатор]
		);
	*/
	protected function hourly() {
		$this->log->notice('hourly() event');
	}
}

$bot = new MyJabberBot('jabber.org', 5222, 'username', 'password', 'ресурс', 10);

/* Запускаем основной цикл. Из него будут вызываться обработчики событий */
$bot->runEventBased();
```

Требования
===

Расширения:
  * PCRE
  * DOM XML
  * Сокеты
  * iconv
