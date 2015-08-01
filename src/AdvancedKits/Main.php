<?php

namespace AdvancedKits;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase implements Listener{

    public $kits;
    public $hasKit = [];
    /**@var EconomyManager*/
    public $economy;
    public $coolDown = [];
    private $permManager = false;

    public function onEnable(){
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        @mkdir($this->getDataFolder());
        if(!file_exists($this->getDataFolder()."kits.yml")){
            $r = $this->getResource("kits.yml");
            $o = stream_get_contents($r);
            fclose($r);
            file_put_contents($this->getDataFolder()."kits.yml", $o);
        }
        $this->kits = yaml_parse(file_get_contents($this->getDataFolder()."kits.yml"));
        $this->saveDefaultConfig();
        $this->economy = new EconomyManager($this);
        if($this->getServer()->getPluginManager()->getPlugin("PurePerms") !== null and $this->getConfig()->get("force-builtin-permissions") == false){
            $this->permManager = true;
        }
        $this->getServer()->getScheduler()->scheduleDelayedRepeatingTask(new CoolDownTask($this), 1200, 1200);
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args){
        switch(strtolower($command->getName())){
            case "kit":
                if(!($sender instanceof Player)){
                    $sender->sendMessage("Please run this command in game");
                    return true;
                }
                if(!isset($args[0])){
                    $sender->sendMessage("Available kits: ".implode(", ", array_keys($this->kits)));
                    return true;
                }
                if(isset($this->hasKit[$sender->getId()])){
                    $sender->sendMessage("You already have a kit");
                    return true;
                }
                if(isset($this->coolDown[strtolower($sender->getName())][strtolower($args[0])])){
                    $sender->sendMessage("Kit ".$args[0]." is in coolDown at the moment");
                    $sender->sendMessage("You will be able to get it in ".$this->getTimeLeft($this->coolDown[strtolower($sender->getName())][strtolower($args[0])]));
                    return true;
                }
                if(!isset($this->kits[strtolower($args[0])])){
                    $sender->sendMessage("Kit ".$args[0]." does not exist");
                    return true;
                }
                if(!$this->checkPermission($sender, strtolower($args[0]))){
                    $sender->sendMessage("You haven't the permission to use kit ".$args[0]);
                    return true;
                }
                if(isset($this->kits[strtolower($args[0])]["money"])){
                    if($this->economy->grantKit($sender, (int) $this->kits[strtolower($args[0])]["money"])){
                        $this->addKit(strtolower($args[0]), $sender);
                        $sender->sendMessage("Selected kit: ".$args[0].". Taken ".$this->kits[strtolower($args[0])]["money"]." money");
                    }else{
                        $sender->sendMessage("You can not afford this kit");
                    }
                }else{
                    $this->addKit(strtolower($args[0]), $sender);
                    $sender->sendMessage("Selected kit: ".$args[0]);
                }
                return true;
            break;
            case "addkituser":
                if(!isset($args[0])){
                    $sender->sendMessage("Please specify a kit");
                    return true;
                }
                if(!isset($args[2])){
                    $sender->sendMessage("Please specify a player");
                    return true;
                }
                if(!isset($this->kits[strtolower($args[0])])){
                    $sender->sendMessage("Kit ".$args[0]." does not exist");
                    return true;
                }
                $this->kits[strtolower($args[0])]["users"][] = strtolower($args[2]);
                $sender->sendMessage("Gave ".$args[1]." permission to use kit ".$args[0]);
                return true;
            break;
            case "addkitworld":
                if(!isset($args[0])){
                    $sender->sendMessage("Please specify a kit");
                    return true;
                }
                if(!isset($args[2])){
                    $sender->sendMessage("Please specify a world");
                    return true;
                }
                if(!isset($this->kits[strtolower($args[0])])){
                    $sender->sendMessage("Kit ".$args[0]." does not exist");
                    return true;
                }
                $this->kits[strtolower($args[0])]["worlds"][] = strtolower($args[2]);
                $sender->sendMessage("Kit ".$args[0]." can now be used in world ".$args[1]);
                return true;
            break;
            case "rmkituser":
                if(!isset($args[0])){
                    $sender->sendMessage("Please specify a kit");
                    return true;
                }
                if(!isset($args[2])){
                    $sender->sendMessage("Please specify a player");
                    return true;
                }
                if(!isset($this->kits[strtolower($args[0])])){
                    $sender->sendMessage("Kit ".$args[0]." does not exist");
                    return true;
                }
                if(($key = array_search(strtolower($args[1]), $this->kits[strtolower($args[0])]["users"])) !== false){
                    unset($this->kits[strtolower($args[0])]["users"][$key]);
                }
                $sender->sendMessage("Taken ".$args[1]." permission to use kit ".$args[0]);
                return true;
            break;
            case "rmkitworld":
                if(!isset($args[0])){
                    $sender->sendMessage("Please specify a kit");
                    return true;
                }
                if(!isset($args[2])){
                    $sender->sendMessage("Please specify a world");
                    return true;
                }
                if(!isset($this->kits[strtolower($args[0])])){
                    $sender->sendMessage("Kit ".$args[0]." does not exist");
                    return true;
                }
                if(($key = array_search(strtolower($args[1]), $this->kits[strtolower($args[0])]["worlds"])) !== false){
                    unset($this->kits[strtolower($args[0])]["worlds"][$key]);
                }
                $sender->sendMessage("Kit ".$args[0]." is no longer available in world ".$args[1]);
                return true;
            break;
        }
        return true;
    }

    public function checkPermission(Player $player, $kitName){
        if($this->permManager){
            return $player->hasPermission("advancedkits.".$kitName);
        }
        return (
            (isset($this->kits[$kitName]["users"]) ? in_array(strtolower($player->getName()), $this->kits[$kitName]["users"]) : true)
            and
            (isset($this->kits[$kitName]["worlds"]) ? in_array(strtolower($player->getName()), $this->kits[$kitName]["worlds"]) : true)
        );
    }

    public function addKit($kitName, Player $player){
        $kit = $this->kits[$kitName];
        $inv = $player->getInventory();
        foreach($kit["items"] as $item){
            $itemData = array_map("intval", explode(":", $item));
            $inv->setItem($inv->firstEmpty(), Item::get($itemData[0], $itemData[1], $itemData[2]));
        }
        foreach(["helmet", "chestplate", "leggings", "boots"] as $armor){
            if(isset($kit[$armor])){
                $armorItem = Item::get((int) $kit[$armor]);
                switch($armor){
                    case "helmet":
                        $inv->setHelmet($armorItem);
                        break;
                    case "chestplate":
                        $inv->setChestplate($armorItem);
                        break;
                    case "leggings":
                        $inv->setLeggings($armorItem);
                        break;
                    case "boots":
                        $inv->setBoots($armorItem);
                        break;
                }
            }
        }
        if(isset($kit["cooldown"]["minutes"])){
            $this->coolDown[strtolower($player->getName())][$kitName] = $kit["cooldown"]["minutes"];
        }
        if(isset($kit["cooldown"]["hours"])){
            $this->coolDown[strtolower($player->getName())][$kitName] += $kit["cooldown"]["hours"] * 60;
        }
        if($this->getConfig()->get("one-kit-per-life") == true){
            $this->hasKit[$player->getId()] = true;
        }
    }

    public function getTimeLeft($minutes){
        if($minutes < 60){
            return $minutes." minutes";
        }
        if(($modulo = $minutes % 60) === 0){
            return ($minutes / 60)." hours";
        }
        return floor($minutes / 60)." hours and ".$modulo." minutes";
    }

}