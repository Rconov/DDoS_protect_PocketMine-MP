<?php

namespace anti\API;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\scheduler\Task;

class API_3_0_1 implements Listener {
    private PluginBase $plugin;
    private array $packetCounter = [];
    private array $ddosIPs = [];
    protected array $bannedIPs = [];
    private Config $dosConfig;
    private Config $ddosConfig;

    public function __construct(PluginBase $plugin) {
        $this->plugin = $plugin;
    }

    public function init(): void {
        $this->plugin->getLogger()->info("Инициализация защиты от DoS/DDoS для API 3.0.1");
        $this->initConfigs();
        
        // Проверяем, включена ли защита DoS/DDoS
        if ($this->dosConfig->get("enable.guard", true) || $this->ddosConfig->get("enable.guard", true)) {
            $this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);

            // Запускаем задачу сброса счетчиков пакетов
            $this->plugin->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
                private API_3_0_1 $api;

                public function __construct(API_3_0_1 $api) {
                    $this->api = $api;
                }

                public function onRun(): void {
                    $this->api->resetPacketCounters();
                }
            }, 20);
        } else {
            $this->plugin->getLogger()->info("Защита DoS/DDoS отключена в конфигурации.");
        }
    }

    public function reloadConfigs(): void {
        $this->initConfigs();
        $this->plugin->getLogger()->info("Конфигурация защиты перезагружена.");
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        $player = $event->getPlayer();
        $ip = $player->getAddress();

        // Проверяем включена ли защита
        if ($this->dosConfig->get("enable.guard", true)) {
            $this->handleDoS($event, $ip, $player);
        }

        if ($this->ddosConfig->get("enable.guard", true)) {
            $this->handleDDoS($event, $ip, $player);
        }
    }

    private function handleDoS(DataPacketReceiveEvent $event, string $ip, $player): void {
        if (isset($this->bannedIPs[$ip])) {
            $event->setCancelled();
            $player->kick("Вы заблокированы за DoS атаку.", false);
            return;
        }

        if (!isset($this->packetCounter[$ip])) {
            $this->packetCounter[$ip] = 0;
        }

        $this->packetCounter[$ip]++;
        $maxPackages = $this->dosConfig->get("max.packages", 100);

        if ($this->packetCounter[$ip] > $maxPackages) {
            $this->banIP($ip, $this->packetCounter[$ip], "DoS");
            $event->setCancelled();
            $player->kick("Вы заблокированы за DoS атаку.", false);
        }
    }

    private function handleDDoS(DataPacketReceiveEvent $event, string $ip, $player): void {
        if (!isset($this->ddosIPs[$ip])) {
            $this->ddosIPs[$ip] = [
                "count" => 0,
                "lastTime" => microtime(true),
            ];
        }

        $currentTime = microtime(true);
        $elapsedTime = $currentTime - $this->ddosIPs[$ip]["lastTime"];
        $this->ddosIPs[$ip]["lastTime"] = $currentTime;
        $this->ddosIPs[$ip]["count"]++;

        $maxRequests = $this->ddosConfig->get("max.requests", 500);

        if ($this->ddosIPs[$ip]["count"] > $maxRequests) {
            $this->banIP($ip, $this->ddosIPs[$ip]["count"], "DDoS");
            $event->setCancelled();
            $player->kick("Вы заблокированы за DDoS атаку.", false);
        } elseif ($elapsedTime > $this->ddosConfig->get("reset.time", 1)) {
            $this->ddosIPs[$ip]["count"] = 0;
        }
    }

    private function banIP(string $ip, int $rate, string $type): void {
        $config = $type === "DoS" ? $this->dosConfig : $this->ddosConfig;
        $banDuration = mt_rand(
            $config->get("ban.min.time", 90),
            $config->get("ban.max.time", 900)
        );
        $this->bannedIPs[$ip] = time() + $banDuration;

        $banMessage = str_replace(
            ["{ip}", "{duration}", "{rate}"],
            [$ip, $banDuration, $rate],
            $config->get("ban.message", "Адрес {ip} заблокирован за подозрение в {type} атаке! Блокировка на {duration} секунд. Пакеты: {rate}.")
        );
        $this->plugin->getLogger()->info($banMessage);

        // Удаляем IP из бана после истечения срока
        $this->plugin->getScheduler()->scheduleDelayedTask(new class($ip, $this) extends Task {
            private string $ip;
            private API_3_0_1 $api;

            public function __construct(string $ip, API_3_0_1 $api) {
                $this->ip = $ip;
                $this->api = $api;
            }

            public function onRun(): void {
                unset($this->api->bannedIPs[$this->ip]);
            }
        }, $banDuration * 20);
    }

    public function resetPacketCounters(): void {
        $this->packetCounter = [];
    }

    private function initConfigs(): void {
        $dosConfigPath = $this->plugin->getDataFolder() . "DoS.yml";
        $ddosConfigPath = $this->plugin->getDataFolder() . "DDoS.yml";

        // Инициализация DoS конфигурации
        if (!file_exists($dosConfigPath)) {
            @mkdir($this->plugin->getDataFolder(), 0777, true);
            $defaultDoSConfig = [
                "enable.guard" => true,
                "max.packages" => 100,
                "ban.min.time" => 90,
                "ban.max.time" => 900,
                "ban.message" => "Адрес {ip} заблокирован за подозрение в DoS атаке! Блокировка на {duration} секунд. Пакеты: {rate} в секунду."
            ];
            $this->dosConfig = new Config($dosConfigPath, Config::YAML, $defaultDoSConfig);
            $this->dosConfig->save();
        } else {
            $this->dosConfig = new Config($dosConfigPath, Config::YAML);
        }

        // Инициализация DDoS конфигурации
        if (!file_exists($ddosConfigPath)) {
            $defaultDDoSConfig = [
                "enable.guard" => true,
                "max.requests" => 900,
                "reset.time" => 1,
                "ban.min.time" => 120,
                "ban.max.time" => 900,
                "ban.message" => "Адрес {ip} заблокирован за подозрение в DDoS атаке! Блокировка на {duration} секунд. Пакеты: {rate} в секунду."
            ];
            $this->ddosConfig = new Config($ddosConfigPath, Config::YAML, $defaultDDoSConfig);
            $this->ddosConfig->save();
        } else {
            $this->ddosConfig = new Config($ddosConfigPath, Config::YAML);
        }
    }
}
