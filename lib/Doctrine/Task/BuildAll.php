<?php
/*
 *  $Id: BuildAll.php 2761 2007-10-07 23:42:29Z zYne $
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

/**
 * Doctrine_Task_BuildAll
 *
 * @package     Doctrine
 * @subpackage  Task
 * @license     http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link        www.doctrine-project.org
 * @since       1.0
 * @version     $Revision: 2761 $
 * @author      Jonathan H. Wage <jwage@mac.com>
 */
class Doctrine_Task_BuildAll extends Doctrine_Task
{
    public $description          =   'Calls generate-models-from-yaml, create-db, and create-tables',
           $requiredArguments    =   array(),
           $optionalArguments    =   array();
    
    protected $models,
              $tables,
              $createDb;
    
    public function __construct($dispatcher = null)
    {
        parent::__construct($dispatcher);
        
        $this->models = new Doctrine_Task_GenerateModelsYaml($this->dispatcher);
        $this->createDb = new Doctrine_Task_CreateDb($this->dispatcher);
        $this->tables = new Doctrine_Task_CreateTables($this->dispatcher);
        
        $this->requiredArguments = array_merge($this->requiredArguments, $this->models->requiredArguments, $this->createDb->requiredArguments, $this->tables->requiredArguments);
        $this->optionalArguments = array_merge($this->optionalArguments, $this->models->optionalArguments, $this->createDb->optionalArguments, $this->tables->optionalArguments);
    }
    
    public function execute()
    {
        $this->models->setArguments($this->getArguments());
        $this->models->execute();
        
        $this->createDb->setArguments($this->getArguments());
        $this->createDb->execute();
        
        $this->tables->setArguments($this->getArguments());
        $this->tables->execute();
    }
}