# Flysystem Adapter for Pydio

This adapter provide a Pydio adapter for Flysystem.

## Installation

```bash
composer require tprog/flysystem-pydio
```

## Usage

```php
use Tprog\Adapter\Pydio;
use League\Flysystem\Filesystem;

$adapter = new Pydio(
	$pydioRestUser,
	$pydioRestPw,
	$pydioRestApi,
	$workspaceId);

$filesystem = new Filesystem($adapter);
```
