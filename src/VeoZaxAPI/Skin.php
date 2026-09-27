<?php
namespace VeoZaxAPI;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\inventory\InventoryPickupItemEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\level\particle\FlameParticle;
use pocketmine\level\sound\AnvilUseSound;
use pocketmine\network\protocol\PlayerListPacket;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginLoadOrder;
use pocketmine\scheduler\CallbackTask;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use VeoZaxAPI\gui\CapeGUI;

class Skin extends PluginBase implements Listener
{
    public $capes = [
        'Steve' => [
            'Minecon_MineconSteveCape2011',
            'Minecon_MineconSteveCape2012',
            'Minecon_MineconSteveCape2013',
            'Minecon_MineconSteveCape2015',
            'Minecon_MineconSteveCape2016',
        ],
        'Alex' => [
            'Minecon_MineconAlexCape2011',
            'Minecon_MineconAlexCape2012',
            'Minecon_MineconAlexCape2013',
            'Minecon_MineconAlexCape2015',
            'Minecon_MineconAlexCape2016',
        ],
    ];
    public $capes2 = [
        'Cape1',
        'Cape2',
        'Cape3',
        'Cape4',
        'Cape5',
    ];
    public $capeYears = ['2011', '2012', '2013', '2015', '2016'];
    private $capeWool = [
        'Cape1' => 14,
        'Cape2' => 11,
        'Cape3' => 5,
        'Cape4' => 12,
        'Cape5' => 7,
    ];
    private $capeDisplayNames = [
        'Cape1' => 'Creeper Cape',
        'Cape2' => 'Diamond Cape',
        'Cape3' => 'Piston Cape',
        'Cape4' => 'Golem Cape',
        'Cape5' => 'Ender Cape',
    ];
    private $activeGUI = [];
    private $purchaseLock = [];
    const CAPE_PRICE = 500;
    const MIN_SUPPORTED_PROTOCOL = 70;
    private $playerCapes;
    private $eco;
    private $activeCapes = [];
    public function onEnable()
    {
        $this->registerFolderPluginLoader();
        @mkdir($this->getDataFolder());
        $this->saveResource('config.yml');
        $this->playerCapes = new Config($this->getDataFolder() . 'playercapes.yml', Config::YAML);
        $this->eco = $this->getServer()->getPluginManager()->getPlugin('EconomyAPI');
        if($this->eco === null)
            {
            $this->getLogger()->warning('EconomyAPI not found! Cape purchases will be unavailable until it is installed.');
        }
        $this->getServer()->getPluginManager()->registerEvents($this,$this);
    }
    private function registerFolderPluginLoader()
    {
        $pluginManager = $this->getServer()->getPluginManager();
        $interface = "VeoZaxAPI\\FolderPluginLoader\\FolderPluginLoader";
        $pluginManager->registerInterface($interface);
        $pluginManager->loadPlugins($this->getServer()->getPluginPath(), [$interface]);
        $this->getServer()->enablePlugins(PluginLoadOrder::STARTUP);
    }
    public function onCommand(CommandSender $sender,Command $cmd,$label,array $args): bool
    {
        if(strtolower($cmd->getName()) === 'bettercape')
{
            return $this->handleBetterCape($sender,$args);
        }
        return false;
    }
    private function handleBetterCape(CommandSender $sender,array $args): bool
    {
        if($sender instanceof Player && !$this->isProtocolSupported($sender))
{
            $sender->sendMessage(TF::RED . $this->getConfig()->get('UnsupportedVersion'));
            return true;
        }
        if(!isset($args[0]))
{
            if($sender instanceof Player)
                {
                $this->openCapeGUI($sender);
                return true;
            }
            $sender->sendMessage(TF::GOLD . TF::BOLD . $this->getConfig()->get('CapesAvailable'));
            foreach($this->capes2 as $cape)
                {
                $sender->sendMessage(
                    TF::AQUA . str_replace('{cape}', $cape, $this->getConfig()->get('Cape'))
                );
            }
            return true;
        }
        if(!in_array($args[0], $this->capes2, true))
{
            $sender->sendMessage(TF::RED . $this->getConfig()->get('CapeUnavailable'));
            return true;
        }
        $target = $sender;
        if(isset($args[1]))
            {
            $player = $this->getServer()->getPlayer($args[1]);
            if($player instanceof Player)
{
                $target = $player;
            }
        }
        if(!$target instanceof Player)
{
            $sender->sendMessage(TF::RED . $this->getConfig()->get('AntiMemberCMDusage'));
            return true;
        }
        $model = $this->getSkinModel($target);
        $cape = $this->getCape($args[0], $model);
        if($cape === null)
            {
            $sender->sendMessage(TF::RED . $this->getConfig()->get('CapeUnavailable'));
            return true;
        }
        $this->applyCape($target, $args[0], $cape);
        $target->sendMessage(TF::GREEN . TF::BOLD . $this->getConfig()->get('CapeOnChange'));
        return true;
    }
    public function applyCape(Player $player,string $capeName,string $capeSkinId)
    {
        $key = strtolower($player->getName());
        $this->activeCapes[$key] = $capeSkinId;
        $this->playerCapes->set($key, $capeName);
        $this->playerCapes->save();
        $this->broadcastCapesToEligibleViewers();
    }
    public function broadcastCapesToEligibleViewers()
    {
        $viewers = array_filter(
            $this->getServer()->getOnlinePlayers(),
            [$this, 'isProtocolSupported']
        );
        if(empty($viewers))
            {
            return;
        }
        $pk = new PlayerListPacket();
        $pk->type = PlayerListPacket::TYPE_ADD;
        foreach($this->getServer()->getOnlinePlayers() as $entry)
            {
            $entryKey = strtolower($entry->getName());
            $skinId = $this->activeCapes[$entryKey] ?? $entry->getSkinId();
            $pk->entries[] = [
                $entry->getUniqueId(),
                $entry->getId(),
                $entry->getDisplayName(),
                $skinId,
                $entry->getSkinData(),
            ];
        }
        foreach($viewers as $viewer)
            {
            $viewer->dataPacket($pk);
        }
    }
    public function openCapeGUI(Player $player)
    {
        $key = strtolower($player->getName());
        $gui = new CapeGUI($this, $player);
        $this->activeGUI[$key] = $gui;
        $player->addWindow($gui);
    }
    public function fillCapeGUI(CapeGUI $inv)
    {
        $inv->clearAll();
        $slot = 0;
        foreach($this->capeGuiEntries() as $entry)
            {
            $item = Item::get(35, $entry['meta'], 1);
            $item->setCustomName($entry['display']);
            $inv->setItem($slot, $item);
            $slot++;
        }
    }
    public function onCapeGUIClose(Player $player)
    {
        $key = strtolower($player->getName());
        unset($this->activeGUI[$key]);
        unset($this->purchaseLock[$key]);
    }
    private function capeGuiEntries()
    {
        $entries = [];
        foreach($this->capes2 as $capeName)
            {
            $niceName = $this->capeDisplayNames[$capeName] ?? $capeName;
            $entries[$capeName] = [
                'meta'    => isset($this->capeWool[$capeName]) ? $this->capeWool[$capeName] : 0,
                'display' => TF::YELLOW . TF::BOLD . $niceName . TF::RESET
                    . "\n" . TF::GRAY . 'Price: ' . TF::GREEN . '$' . number_format(self::CAPE_PRICE),
            ];
        }
        return $entries;
    }
    public function onInventoryTransaction(InventoryTransactionEvent $event)
    {
        $queue = $event->getTransaction();
        foreach($queue->getTransactions() as $transaction)
            {
            $inv = $transaction->getInventory();
            if(!($inv instanceof CapeGUI))
                {
                continue;
            }
            $player = $inv->getOwnerPlayer();
            if(!($player instanceof Player))
                {
                continue;
            }
            $key = strtolower($player->getName());
            if(!isset($this->activeGUI[$key]))
                {
                continue;
            }
            $event->setCancelled(true);
            $inv->sendContents($player);
            $player->getInventory()->sendContents($player);
            $this->stripStrayCapeWool($player);

            if(isset($this->purchaseLock[$key]))
                {
                return;
            }
            $itemName = $inv->getItem($transaction->getSlot());
            $itemName = $itemName !== null ? $itemName->getCustomName() : '';
            foreach($this->capeGuiEntries() as $capeName => $entry)
                {
                if($itemName !== $entry['display'])
                    {
                    continue;
                }
                $this->purchaseLock[$key] = true;
                $this->purchaseCape($player, $capeName);
                break;
            }
            return;
        }
    }
    private function purchaseCape(Player $player,string $capeName)
    {
        $key = strtolower($player->getName());
        $model = $this->getSkinModel($player);
        $cape = $this->getCape($capeName, $model);
        if($cape === null)
            {
            unset($this->purchaseLock[$key]);
            return;
        }
        if($this->eco === null)
            {
            $player->sendMessage(TF::RED . $this->getConfig()->get('EconomyPluginMissing'));
            unset($this->purchaseLock[$key]);
            return;
        }
        $balance = (float)$this->eco->myMoney($player);
        if($balance < self::CAPE_PRICE)
            {
            $needed = self::CAPE_PRICE - $balance;
            $player->sendMessage(TF::RED . str_replace(
                '{needed}',
                number_format($needed),
                $this->getConfig()->get('NotEnoughMoney')
            ));
            unset($this->purchaseLock[$key]);
            return;
        }
        $this->eco->reduceMoney($player, self::CAPE_PRICE);
        $this->applyCape($player, $capeName, $cape);
        $niceName = $this->capeDisplayNames[$capeName] ?? $capeName;
        $player->sendMessage(TF::GREEN . TF::BOLD . str_replace(
            ['{cape}', '{price}'],
            [$niceName, number_format(self::CAPE_PRICE)],
            $this->getConfig()->get('CapeOnPurchase')
        ));
        $player->getLevel()->addParticle(new FlameParticle($player));
        $player->getLevel()->addSound(new AnvilUseSound($player));
        $this->getServer()->getScheduler()->scheduleDelayedTask(
            new CallbackTask([$this, 'closeCapeGuiFor'], [$player]), 1
        );
    }
    private function stripStrayCapeWool(Player $player)
    {
        $inv = $player->getInventory();
        foreach($inv->getContents() as $slot => $item)
            {
            if($this->isCapeWoolItem($item))
                {
                $inv->clear($slot);
            }
        }
    }
    private function isCapeWoolItem(Item $item): bool
    {
        if($item->getId() !== 35)
            {
            return false;
        }
        foreach($this->capeGuiEntries() as $entry)
            {
            if($item->getCustomName() === $entry['display'])
                {
                return true;
            }
        }
        return false;
    }
    public function closeCapeGuiFor(Player $player)
    {
        $key = strtolower($player->getName());
        if($player->isConnected() && isset($this->activeGUI[$key]))
            {
            $player->removeWindow($this->activeGUI[$key]);
        }
    }
    public function onJoin(PlayerJoinEvent $event)
    {
        $player = $event->getPlayer();
        $key = strtolower($player->getName());

        if($this->getConfig()->get('RemoveCapeOnJoin'))
{
            unset($this->activeCapes[$key]);
            $this->playerCapes->remove($key);
            $this->playerCapes->save();
        }else{
            $savedCape = $this->playerCapes->get($key, null);
            if(is_string($savedCape) && in_array($savedCape, $this->capes2, true))
                {
                $model = $this->getSkinModel($player);
                $cape = $this->getCape($savedCape, $model);
                if($cape !== null)
                    {
                    $this->activeCapes[$key] = $cape;
                }
            }
        }
        $this->getServer()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, 'broadcastCapesToEligibleViewers']),5);
    }
    public function onQuit(PlayerQuitEvent $event)
    {
        unset($this->activeCapes[strtolower($event->getPlayer()->getName())]);
    }
    public function onPlayerDropItem(PlayerDropItemEvent $event)
    {
        if($this->isCapeWoolItem($event->getItem()))
            {
            $event->setCancelled(true);
        }
    }
    public function onInventoryPickupItem(InventoryPickupItemEvent $event)
    {
        if($this->isCapeWoolItem($event->getItem()->getItem()))
            {
            $event->setCancelled(true);
            $event->getItem()->kill();
        }
    }
    public function isProtocolSupported(Player $player): bool
    {
        return $player->getProtocol() > self::MIN_SUPPORTED_PROTOCOL;
    }
    public function getCape(string $cape, string $skinModel)
    {
        $index = array_search($cape, $this->capes2, true);

        if($index === false && preg_match('/(\d{4})$/', $cape, $matches))
{
            $index = array_search($matches[1], $this->capeYears, true);
        }
        if($index === false || !isset($this->capes[$skinModel][$index]))
{
            return null;
        }
        return $this->capes[$skinModel][$index];
    }
    public function getSkinModel(Player $player): string
    {
        $skinId = $player->getSkinId();

        if(in_array($skinId, ['Standard_CustomSlim', 'Standard_Alex'], true)
            || in_array($skinId, $this->capes['Alex'], true))
        {
            return 'Alex';
        }
        return 'Steve';
    }
}