# WooCommerce UpdatePulse License & Update Server (`wc-plugin-update-server`)

![WordPress Plugin](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)
![PHP Support](https://img.shields.io/badge/PHP-7.4%20%7C%208.0%20%7C%208.1%20%7C%208.2%20%7C%208.3-777BB4.svg)
![License](https://img.shields.io/badge/License-GPLv2-green.svg)

Интеграция WooCommerce с сервером обновлений и лицензирования UpdatePulse Server для автоматической генерации лицензий после оплаты заказа.

---

## 🚀 Возможности

- 🔑 **Автоматическая выдача ключей:** Генерация лицензионного ключа сразу после успешной оплаты заказа WooCommerce.
- 🔄 **Авто-обновления:** Связка продуктов WooCommerce с репозиторием UpdatePulse Server.
- 💼 **Управление продлением:** Возможность продления лицензий клиентами из личного кабинета.

---

## 📥 Установка

### Через Composer (рекомендуется)
```bash
composer require tikhomirov/wc-plugin-update-server
```

### Вручную
1. Скачайте ZIP-архив репозитория.
2. Распакуйте в директорию `/wp-content/plugins/wc-plugin-update-server/`.
3. Активируйте плагин в админ-панели **Плагины → Установленные**.

---

## 💻 Использование

1. Укажите URL и API-ключ вашего UpdatePulse Server в настройках плагина.
2. В свойствах товара WooCommerce выберите соответствующий программный продукт.

---

## 🛠️ Требования

- **WordPress:** 5.0 или выше
- **PHP:** 7.4, 8.0, 8.1, 8.2, 8.3
