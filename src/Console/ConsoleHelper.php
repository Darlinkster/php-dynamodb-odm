<?php
/**
 * Created by PhpStorm.
 * User: minhao
 * Date: 2016-09-18
 * Time: 18:05
 */

namespace Darlinkster\Mlib\ODM\Dynamodb\Console;

use Darlinkster\Mlib\ODM\Dynamodb\Console\Commands\CreateSchemaCommand;
use Darlinkster\Mlib\ODM\Dynamodb\Console\Commands\DropSchemaCommand;
use Darlinkster\Mlib\ODM\Dynamodb\Console\Commands\UpdateSchemaCommand;
use Darlinkster\Mlib\ODM\Dynamodb\ItemManager;
use Symfony\Component\Console\Application;

class ConsoleHelper
{
    /**
     * @var ItemManager
     */
    protected $itemManager;
    
    public function __construct(ItemManager $itemManager)
    {
        $this->itemManager = $itemManager;
    }
    
    public function addCommands(Application $application)
    {
        $application->addCommands(
            [
                (new CreateSchemaCommand())->withItemManager($this->itemManager),
                (new DropSchemaCommand())->withItemManager($this->itemManager),
                (new UpdateSchemaCommand())->withItemManager($this->itemManager),
            ]
        );
    }
    
    /**
     * @return ItemManager
     */
    public function getItemManager()
    {
        return $this->itemManager;
    }
}
