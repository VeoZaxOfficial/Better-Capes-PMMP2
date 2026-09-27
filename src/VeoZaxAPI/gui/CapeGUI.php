<?php
namespace VeoZaxAPI\gui;
use pocketmine\inventory\CustomInventory;
use pocketmine\inventory\InventoryType;
use pocketmine\network\protocol\UpdateBlockPacket;
use pocketmine\network\protocol\ContainerOpenPacket;
use pocketmine\network\protocol\ContainerClosePacket;
use pocketmine\network\protocol\BlockEntityDataPacket;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\NBT;
use pocketmine\tile\Tile;
use pocketmine\Player;
use VeoZaxAPI\Skin;

class CapeGUI extends CustomInventory
{
    private $plugin;
    private $fakeHolder;
    private $ownerPlayer;

    public function __construct(Skin $plugin,Player $p)
    {
        $this->plugin = $plugin;
        $this->ownerPlayer = $p;
        $this->fakeHolder = new FakeHolder(
            (int)$p->x,
            (int)$p->y + 2,
            (int)$p->z
        );
        parent::__construct($this->fakeHolder, InventoryType::get(InventoryType::CHEST));
        $this->fakeHolder->setInventory($this);
    }
    public function getOwnerPlayer()
    {
        return $this->ownerPlayer;
    }
    public function onOpen(Player $who)
    {
        $this->viewers[spl_object_hash($who)] = $who;
        $x = (int)$this->fakeHolder->x;
        $y = (int)$this->fakeHolder->y;
        $z = (int)$this->fakeHolder->z;
        $blockPk = new UpdateBlockPacket();
        $blockPk->x = $x;
        $blockPk->z = $z;
        $blockPk->y = $y;
        $blockPk->blockId = 54;
        $blockPk->blockData = 0;
        $blockPk->flags = UpdateBlockPacket::FLAG_ALL;
        $who->dataPacket($blockPk);
        $nbtData = new CompoundTag("", array(
            new StringTag("id", Tile::CHEST),
            new IntTag("x", $x),
            new IntTag("y", $y),
            new IntTag("z", $z),
            new StringTag("CustomName", "Select a Cape"),
        ));
        $nbt = new NBT(NBT::LITTLE_ENDIAN);
        $nbt->setData($nbtData);
        $nbtPk = new BlockEntityDataPacket();
        $nbtPk->x = $x;
        $nbtPk->y = $y;
        $nbtPk->z = $z;
        $nbtPk->namedtag = $nbt->write();
        $who->dataPacket($nbtPk);
        $openPk = new ContainerOpenPacket();
        $openPk->windowid = $who->getWindowId($this);
        $openPk->type = $this->getType()->getNetworkType();
        $openPk->slots = $this->getSize();
        $openPk->x = $x;
        $openPk->y = $y;
        $openPk->z = $z;
        $who->dataPacket($openPk);
        $this->plugin->fillCapeGUI($this);
        $this->sendContents($who);
    }
    public function onClose(Player $who)
    {
        $x = (int)$this->fakeHolder->x;
        $y = (int)$this->fakeHolder->y;
        $z = (int)$this->fakeHolder->z;
        $blockPk = new UpdateBlockPacket();
        $blockPk->x = $x;
        $blockPk->z = $z;
        $blockPk->y = $y;
        $blockPk->blockId = $who->getLevel()->getBlockIdAt($x, $y, $z);
        $blockPk->blockData = $who->getLevel()->getBlockDataAt($x, $y, $z);
        $blockPk->flags = UpdateBlockPacket::FLAG_ALL;
        $who->dataPacket($blockPk);
        $closePk = new ContainerClosePacket();
        $closePk->windowid = $who->getWindowId($this);
        $who->dataPacket($closePk);
        $this->plugin->onCapeGUIClose($who);
        unset($this->viewers[spl_object_hash($who)]);
    }
}