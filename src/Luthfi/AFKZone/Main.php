<?php


#            ______ _  __ ______                
#      /\   |  ____| |/ /|___  /                
#     /  \  | |__  | ' /    / / ___  _ __   ___ 
#    / /\ \ |  __| |  <    / / / _ \| '_ \ / _ \
#   / ____ \| |    | . \  / /_| (_) | | | |  __/
#  /_/    \_\_|    |_|\_\/_____\___/|_| |_|\___|
#                                               
# © LuthMC
#
# Github: https://github.com/LuthMC
# Thanks to fernanACM

declare(strict_types=1);

namespace Luthfi\AFKZone;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\FloatingTextParticle;
use pocketmine\math\Vector3;
use pocketmine\world\World;
use jojoe77777\FormAPI\SimpleForm;
use Ifera\ScoreHud\event\TagsResolveEvent;
use Ifera\ScoreHud\ScoreHud;
use Ifera\ScoreHud\scoreboard\ScoreTag;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\world\sound\NoteBlockSound;
use onebone\economyapi\EconomyAPI;
use cooldogepm\bedrockeconomy\api\BedrockEconomyAPI;

class Main extends PluginBase implements Listener {

    private $afkZone;
    private $playersInZone = [];
    private $economyPlugin;
    private $bedrockEconomyAPI;
    private $leaderboardParticles = [];
    private $messages = [];
    protected const DATAFOLDER_NAME = "Language";

    public const LANGUAGES = [
        "English", // English
        "Indonesia", // Indonesia
    ];

    /** @var Config $messages */
    protected static Config $messages;

    /**
     * @return void
     */
    public static function init(): void{
        @mkdir(Loader::getInstance()->getDataFolder(). self::DATAFOLDER_NAME);
        foreach(self::LANGUAGES as $language){
            Loader::getInstance()->saveResource(self::DATAFOLDER_NAME."/$language.yml");
        }
        self::loadMessages();
    }

    /**
     * @return void
     */
    public static function loadMessages(): void{
        self::$messages = new Config(Loader::getInstance()->getDataFolder().self::DATAFOLDER_NAME."/".self::getLanguage().".yml");
    }

    /**
     * @return string
     */
    public static function getLanguage(): string{
        return strval(Loader::getInstance()->config->get("Language", "English"));
    }

    /**
     * @param Player $player
     * @param string $key
     * @param array $replaces
     * @return string
     */
    public static function getPlayerMessage(Player $player, string $key, array $replaces = []): string{
        $messageArray = self::$messages->getNested($key, []);
        if(!is_array($messageArray)){
            $messageArray = [$messageArray];
        }
        $message = implode("\n", $messageArray);
        foreach($replaces as $search => $replace){
            $message = str_replace($search, (string)$replace, $message);
        }
        return PluginUtils::codeUtil($player, $message);
    }

    /**
     * @param string $key
     * @param array $replaces
     * @return string
     */
    public static function getMessage(string $key, array $replaces = []): string{
        $messageArray = self::$messages->getNested($key, []);
        if(!is_array($messageArray)){
            $messageArray = [$messageArray];
        }
        $message = implode("\n", $messageArray);
        foreach($replaces as $search => $replace){
            $message = str_replace($search, (string)$replace, $message);
        }
        return TextFormat::colorize($message);
    }
}

    public function onEnable(): void {
        $this->loadLanguage();
        $this->saveDefaultConfig();
        $this->saveResource("Language");
        $this->afkZone = $this->getConfig()->get("afk-zone", []);

        $language = $this->getConfig()->get("Language", "English");
        $languageFile = $this->getDataFolder() . "Language/" . $language . ".yml";

    if (!file_exists($languageFile)) {
        $this->getLogger()->error("Language file not found: " . $languageFile);
    } else {
        $this->loadLanguage($languageFile);
    }

        $economy = $this->getConfig()->get("economy-plugin", "EconomyAPI");
        if ($economy === "BedrockEconomy") {
            $bedrockEconomy = $this->getServer()->getPluginManager()->getPlugin("BedrockEconomy");
            if ($bedrockEconomy !== null && $bedrockEconomy->isEnabled()) {
                $this->economyPlugin = "BedrockEconomy";
                if (class_exists(BedrockEconomyAPI::class)) {
                    $this->bedrockEconomyAPI = BedrockEconomyAPI::getInstance();
                } else {
                    $this->getLogger()->error("BedrockEconomyAPI class not found!");
                    $this->economyPlugin = "EconomyAPI";
                }
            } else {
                $this->getLogger()->error("BedrockEconomy plugin not found or not enabled! Defaulting to EconomyAPI.");
                $this->economyPlugin = "EconomyAPI";
            }
        } else {
            $this->economyPlugin = "EconomyAPI";
        }

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->checkAfkZone();
        }), 20);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->updatePlayerTimes();
            $this->updateLeaderboard();
        }), 20 * 60);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getPluginManager()->registerEvents(new class($this) implements Listener {
    private $plugin;

/**
 * Loads the language file
 * 
 * @param string $file
 */
            
private function loadLanguage(string $file): void {
    $langConfig = new Config($file, Config::YAML);
    $this->messages = $langConfig->getAll();
}

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        ScoreHud::getInstance()->setScoreTag(new ScoreTag($player, "afkzone.time", "0s"));
    }

    public function onTagsResolve(TagsResolveEvent $event): void {
        $tag = $event->getTag();
        $player = $event->getPlayer();
        if ($tag->getName() === "afkzone.time") {
            $timeInZone = $this->plugin->playersInZone[$player->getName()] ?? 0;
            $hours = floor($timeInZone / 3600);
            $minutes = floor(($timeInZone % 3600) / 60);
            $seconds = $timeInZone % 60;
            $timeString = sprintf("%dh %dm %ds", $hours, $minutes, $seconds);
            $tag->setValue($timeString);
            }
        }
    }, $this);
}

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
    if ($command->getName() === "afkzone") {
        if (!$sender instanceof Player) {
            $sender->sendMessage($this->translate("in_game_only"));
            return true;
        }

        if (!$sender->hasPermission("afkzone.command")) {
            $sender->sendMessage($this->translate("no_permission"));
            return true;
        }

        $player = $sender;
        $player->getWorld()->addSound($player->getPosition(), new NoteBlockSound(NoteBlockSound::PITCH_BELL));

        if (count($args) < 1) {
            $sender->sendMessage($this->translate("usage_afkzone"));
            return true;
        }

        switch ($args[0]) {
            case "ui":
                $this->showAfkZoneForm($sender);
                break;
            case "setworld":
                $this->setAfkZoneWorld($sender);
                break;
            case "setposition":
                if (!isset($this->afkZone['world'])) {
                    $sender->sendMessage($this->translate("must_set_world"));
                    return true;
                }
                if (count($args) < 2) {
                    $sender->sendMessage($this->translate("usage_afkzone"));
                    return true;
                }
                $this->setAfkZonePosition($sender, $args[1]);
                break;
            default:
                $sender->sendMessage($this->translate("usage_afkzone"));
                return true;
        }
        return true;
    } elseif ($command->getName() === "settopafk") {
        if (!$sender instanceof Player) {
            $sender->sendMessage($this->translate("in_game_only"));
            return true;
        }

        if (!$sender->hasPermission("afkzone.settopafk")) {
            $sender->sendMessage($this->translate("no_permission"));
            return true;
        }

        $player = $sender;
        $player->getWorld()->addSound($player->getPosition(), new NoteBlockSound(NoteBlockSound::PITCH_BELL));

        $this->setTopAfkPosition($sender);
        return true;
    }

    return false;
}

    private function loadLanguage(): void {
    $language = $this->getConfig()->get("Language", "English");
    $languageFile = $this->getDataFolder() . "Language/" . $language . ".yml";
    
    if (!file_exists($languageFile)) {
        $this->getLogger()->error("Language file for '$language' not found, defaulting to English.");
        $languageFile = $this->getDataFolder() . "Language/English.yml";
    }
    
    $this->messages = yaml_parse_file($languageFile)["messages"];
 }

    private function translate(string $key, array $params = []): string {
    $message = $this->messages[$key] ?? $key;
    foreach ($params as $key => $value) {
        $message = str_replace("{" . $key . "}", $value, $message);
    }
    return $message;
}
    
    private function setAfkZoneWorld(Player $player): void {
    $worldName = $player->getWorld()->getFolderName();
    $this->afkZone['world'] = $worldName;
    $this->getConfig()->set("afk-zone.world", $worldName);
    $this->getConfig()->save();
    $player->sendMessage($this->translate("world_set", ["world" => $worldName]));
}

    private function setAfkZonePosition(Player $player, string $position): void {
    $x = $player->getPosition()->getX();
    $y = $player->getPosition()->getY();
    $z = $player->getPosition()->getZ();

    if ($position === "1") {
        $this->afkZone['x1'] = $x;
        $this->afkZone['y1'] = $y;
        $this->afkZone['z1'] = $z;
        $this->getConfig()->set("afk-zone.x1", $x);
        $this->getConfig()->set("afk-zone.y1", $y);
        $this->getConfig()->set("afk-zone.z1", $z);
        $player->sendMessage($this->translate("set_position_1", ["x" => $x, "y" => $y, "z" => $z]));
    } elseif ($position === "2") {
        $this->afkZone['x2'] = $x;
        $this->afkZone['y2'] = $y;
        $this->afkZone['z2'] = $z;
        $this->getConfig()->set("afk-zone.x2", $x);
        $this->getConfig()->set("afk-zone.y2", $y);
        $this->getConfig()->set("afk-zone.z2", $z);
        $player->sendMessage($this->translate("set_position_2", ["x" => $x, "y" => $y, "z" => $z]));
    } else {
        $player->sendMessage($this->translate("invalid_position"));
        return;
    }

    $this->getConfig()->save();
}

    private function setTopAfkPosition(Player $player): void {
        $x = $player->getPosition()->getX();
        $y = $player->getPosition()->getY();
        $z = $player->getPosition()->getZ();
        $world = $player->getWorld()->getFolderName();

        $this->getConfig()->set("leaderboard.position.x", $x);
        $this->getConfig()->set("leaderboard.position.y", $y);
        $this->getConfig()->set("leaderboard.position.z", $z);
        $this->getConfig()->set("leaderboard.position.world", $world);
        $this->getConfig()->save();

        $player->sendMessage("Leaderboard position set to X: $x, Y: $y, Z: $z in world: $world");
    }
    
    public function checkAfkZone(): void {
    foreach ($this->getServer()->getOnlinePlayers() as $player) {
        if ($this->isInAfkZone($player)) {
            if (!isset($this->playersInZone[$player->getName()])) {
                $this->playersInZone[$player->getName()] = time();
            }
        } else {
            if (isset($this->playersInZone[$player->getName()])) {
                unset($this->playersInZone[$player->getName()]);
                $player->sendTitle("", "");
                ScoreHud::getInstance()->setScoreTag(new ScoreTag($player, "afkzone.time", "0s"));
            }
        }
    }
}

    private function isInAfkZone(Player $player): bool {
        $pos = $player->getPosition();
        $worldName = $player->getWorld()->getFolderName();
        if (!isset($this->afkZone['world']) || $this->afkZone['world'] !== $worldName) {
            return false;
        }
        return (
            $pos->getX() >= min($this->afkZone['x1'], $this->afkZone['x2']) &&
            $pos->getX() <= max($this->afkZone['x1'], $this->afkZone['x2']) &&
            $pos->getY() >= min($this->afkZone['y1'], $this->afkZone['y2']) &&
            $pos->getY() <= max($this->afkZone['y1'], $this->afkZone['y2']) &&
            $pos->getZ() >= min($this->afkZone['z1'], $this->afkZone['z2']) &&
            $pos->getZ() <= max($this->afkZone['z1'], $this->afkZone['z2'])
        );
    }

    private function grantMoney(Player $player): void {
        $amount = $this->getConfig()->get("reward-amount", 1000);
        if ($this->economyPlugin === "BedrockEconomy") {
            if ($this->bedrockEconomyAPI !== null) {
                $this->bedrockEconomyAPI->addToPlayerBalance($player->getName(), $amount, function (bool $success) use ($player, $amount): void {
                    if ($success) {
                        $player->sendMessage("You have received $amount for being in the AFK zone!");
                    } else {
                        $player->sendMessage("Failed to add money to your account.");
                    }
                });
            }
        } else {
            EconomyAPI::getInstance()->addMoney($player, $amount);
            $player->sendMessage("You have received $amount for being in the AFK zone!");
        }
    }

   private function updatePlayerTimes(): void {
    foreach ($this->playersInZone as $name => $enterTime) {
        $player = $this->getServer()->getPlayerExact($name);
        if ($player instanceof Player) {
            $timeInZone = time() - $enterTime;
            $hours = floor($timeInZone / 3600);
            $minutes = floor(($timeInZone % 3600) / 60);
            $seconds = $timeInZone % 60;
            $player->sendTitle("§fAFK§bZone", "§7Time: {$hours}h {$minutes}m {$seconds}s", 0, 20, 0);

            ScoreHud::getInstance()->setScoreTag(new ScoreTag($player, "afkzone.time", "{$hours}h {$minutes}m {$seconds}s"));

            if ($timeInZone > 0 && $timeInZone % 60 === 0) {
                $this->grantMoney($player);
            }
        }
    }
 }

   private function showAfkZoneForm(Player $player): void {
    $form = new SimpleForm(function (Player $player, ?int $data) {
        if ($data === null) {
            return;
        }

        $player->getWorld()->addSound($player->getPosition(), new NoteBlockSound());
        
        switch ($data) {
            case 0:
                $this->setAfkZoneWorld($player);
                break;
            case 1:
                if (!isset($this->afkZone['world'])) {
                    $player->sendMessage($this->translate("must_set_world"));
                    return;
                }
                $this->setAfkZonePosition($player, "1");
                break;
            case 2:
                if (!isset($this->afkZone['world'])) {
                    $player->sendMessage($this->translate("must_set_world"));
                    return;
                }
                $this->setAfkZonePosition($player, "2");
                break;
            case 3:
                $this->setTopAfkPosition($player);
                break;
            case 4:
                $this->unsetAfkLeaderboardPosition($player);
                break;
        }
    });

    $form->setTitle("§l§fAFK§bZone §eSettings");
    $form->setContent("§6Please choose an option:");
       
    $form->addButton("Set AFKZone World\n§7Click here", -1, "", 1);
    $form->addButton("Set AFKZone Position 1\n§7Click here", -1, "", 2);
    $form->addButton("Set AFKZone Position 2\n§7Click here", -1, "", 3);
    $form->addButton("Set Leaderboard Position\n§7Click here", -1, "", 4);
    $form->addButton("Unset Leaderboard Position\n§7Click here", -1, "", 5);
       
    $player->sendForm($form);
 }   

    private function unsetAfkLeaderboardPosition(Player $player): void {
    $config = $this->getConfig();
    if ($config->exists("leaderboard.position")) {
        $config->remove("leaderboard.position.world");
        $config->remove("leaderboard.position.x");
        $config->remove("leaderboard.position.y");
        $config->remove("leaderboard.position.z");
        $config->save();
        $player->sendMessage($this->translate("leaderboard_unset"));
    } else {
        $player->sendMessage($this->translate("leaderboard_not_set"));
    }
}
    
    private function updateLeaderboard(): void {
        arsort($this->playersInZone);
        $topPlayers = array_slice($this->playersInZone, 0, 5, true);

        foreach ($this->leaderboardParticles as $particle) {
            $particle->getWorld()->addParticle($particle->getPosition(), new FloatingTextParticle("", ""));
        }
        $this->leaderboardParticles = [];

        $config = $this->getConfig();
        if (!$config->exists("leaderboard.position.world")) {
            $this->getLogger()->warning("Leaderboard position is not set.");
            return;
        }

        $worldName = $config->get("leaderboard.position.world");
        $world = $this->getServer()->getWorldManager()->getWorldByName($worldName);
        if ($world === null) {
            $this->getLogger()->warning("World '$worldName' not found.");
            return;
        }

        $x = $config->get("leaderboard.position.x");
        $y = $config->get("leaderboard.position.y");
        $z = $config->get("leaderboard.position.z");
        $basePosition = new Vector3($x, $y, $z);

        $index = 0;
        foreach ($topPlayers as $name => $time) {
            $timeText = gmdate("H:i:s", $time);
            $text = "§e{$name}: §7{$timeText}";

            $position = $basePosition->add(0, $index * 1.5, 0);
            $particle = new FloatingTextParticle($text);
            $world->addParticle($position, $particle);

            $this->leaderboardParticles[] = $particle;
            $index++;
        }
    }
}
