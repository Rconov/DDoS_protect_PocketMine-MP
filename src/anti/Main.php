<?php

/**
 * Плагин DDoS
 *
 * Автор: PluginBase
 * Лицензия: PluginBase Implement License (PBI)
 *
 * Copyright (c) 2025 PluginBase
 */

namespace anti;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;

class Main extends PluginBase {
    private $apiHandler;
    private $supportedAPIs = [
        "3.0.1" => "anti\\API\\API_3_0_1",
        "3.0.0" => "anti\\API\\API_3_0_0"
    ];

    public function onEnable(): void {
        $apiVersion = $this->getServer()->getApiVersion();

        // Вывод доступных версий API
        $this->getLogger()->info("Доступные версии API: " . implode(", ", array_keys($this->supportedAPIs)));

        if (!isset($this->supportedAPIs[$apiVersion])) {
            $this->getLogger()->error(TextFormat::RED . "Нет поддержки для текущей версии API: $apiVersion.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        $class = $this->supportedAPIs[$apiVersion];
        if (class_exists($class)) {
            $this->apiHandler = new $class($this);
            $this->apiHandler->init();
            $this->getLogger()->info(TextFormat::GREEN . "Загружен обработчик для API версии $apiVersion.");
        } else {
            $this->getLogger()->error(TextFormat::RED . "Класс $class не найден для API версии $apiVersion.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
        }
    }
}
