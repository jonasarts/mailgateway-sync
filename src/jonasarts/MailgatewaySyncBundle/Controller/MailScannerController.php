<?php

namespace jonasarts\MailgatewaySyncBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use jonasarts\MailgatewaySyncBundle\Entity\Domain;
use jonasarts\MailgatewaySyncBundle\Entity\Rule;

class MailScannerController extends Controller
{
    private function checkDirectory($directory)
    {
        if (!is_dir($directory)) {
            return "Folder ".$directory." does not exist!<br>\n";
        }
        if (!is_writable($directory)) { // check write permission
            return "Folder ".$directory." is not writable!<br>\n";
        } else {
            return "Folder ".$directory." present.<br>\n";
        }
    }

    private function checkFile($file)
    {
        if (!file_exists($file)) {
            touch($file);
            return "File ".$file." created.<br>\n";
        } elseif (!is_writable($file)) { // check write permission
            return "Can not write file ".$file."!<br>\n";
        } else {
            return "File ".$file." present.<br>\n";
        }
    }

    private function getConfig()
    {
        $chroot = "";
        if ($this->container->hasParameter('chroot')) {
            $chroot = $this->container->getParameter('chroot');
        }

        $config = array();
        $config['path'] = array();
        $config['path']['conf'] = $chroot."/etc/MailScanner/conf.d"; 
        $config['path']['rules'] = $chroot."/etc/MailScanner/rules";
        $config['path']['spam'] = $chroot."/etc/MailScanner/spam.bydomain";
        $config['path']['blacklist'] = $chroot."/etc/MailScanner/spam.bydomain/blacklist";
        $config['path']['whitelist'] = $chroot."/etc/MailScanner/spam.bydomain/whitelist";
        $config['path']['quarantine'] = $chroot."/var/spool/MailScanner/quarantine";
        $config['files'] = array();
        $config['files']['mailscanner_rules'] = $chroot."/etc/MailScanner/conf.d/webfinity.conf";

        return $config;
    }

    private function checkMailScanner($config)
    {
        if ($this->container->getParameter('mode') != 'custom') {
            die('Not operating in custom mode!');
        }

        // filesystem checks
        $msg = "<h1>MailScanner Check</h1>\n";;
    
        //$msg .= $this->checkDirectory($config['path']['conf']);
        $msg .= $this->checkFile($config['files']['mailscanner_rules']);

        $msg .= $this->checkDirectory($config['path']['rules']);

        $msg .= $this->checkDirectory($config['path']['spam']);
        $msg .= $this->checkDirectory($config['path']['blacklist']);
        $msg .= $this->checkDirectory($config['path']['whitelist']);

        $msg .= $this->checkDirectory($config['path']['quarantine']);

        return $msg;
    }

    private function syncMailScanner($config, $force = false)
    {
        if ($this->container->getParameter('mode') != 'custom') {
            die('Not operating in custom mode!');
        }

        $msg = "<h1>Sync MailScanner</h1>\n";

        $default_em = $this->get('doctrine')->getManager();
        $master_em = $this->get('doctrine')->getManager('master');

        // get remote domains (for quarantine)
        $domains = array();

        $query = "SELECT domains.id, domains.name, domains.destination ".
                "FROM domains ".
                "ORDER BY domains.name ASC";

        // query master server
        $connection = $master_em->getConnection();
        $statement = $connection->prepare($query);
        $result = $statement->execute();

        if ($result) {
            $records = $statement->fetchAll();

            $msg .= "RemoteDomainsCount = ". count($records) ."<br><br>\n";
            
            foreach ($records as $record) {
                //var_dump($record);
                $d = Domain::create($record);
                $domains[] = $d;
            }
        }

        // get remote rules
        $rules = array();

        $query = "SELECT rules.id, rules.name, rules.default ".
                "FROM rules ".
                "ORDER BY rules.name ASC";
        
        // query master server
        $connection = $master_em->getConnection();
        $statement = $connection->prepare($query);
        $result = $statement->execute();

        if ($result) {
            $records = $statement->fetchAll();

            $msg .= "AllRulesCount = ". count($records) ."<br><br>\n";

            foreach ($records as $record) {
                //var_dump($record);
                $r = Rule::create($record);
                $rules[] = $r;
            }
        }

        // sync quarantine
        // - create per domain quarantine directories
        /*
        foreach($domains as $domain) {
            $directory = $config['path']['quarantine']."/".$domain->getDomainname();
            if (!is_dir($directory)) {
                mkdir($directory, 0755);
                
                $msg .= "Quarantine Folder for " . $domain->getDomainname() . " created<br>\n";
            }
        }
        */

        $rm = $this->get('registry_manager'); // get registy service

        // sync mailscanner spam lists
        // - blacklist
        // - whitelist

        $query = "SELECT d.id, d.name, b.list ".
            "FROM mailscanner_blacklist AS b ".
            "INNER JOIN domains AS d ON b.master_id = d.id ".
            "ORDER BY d.name ASC";

        $connection = $master_em->getConnection();
        $statement = $connection->prepare($query);
        $result = $statement->execute();
    
        if ($result) {
            $records = $statement->fetchAll();

            foreach ($records as $record) {
                $csr = hash('md5', $record['list']); // remote checksum
                $csl = $rm->RegistryRead($record['id'], 'mailscanner', 'blacklist', 'str'); // local checksum

                if ($csl) { // update
                    if (($csr != $csl) || $force) { // update
                        file_put_contents($config['path']['blacklist']."/".$record['name'], $record['list']);
                        
                        $rm->RegistryWrite($record['id'], 'mailscanner', 'blacklist', 'str', $csr);
                        
                        $msg .= "MailScanner Blacklist for ".$record['name']." updated<br>";
                    } else {
                        $msg .= "MailScanner Blacklist for ".$record['name']." already up2date<br>";
                    }
                } else { // insert
                    file_put_contents($config['path']['blacklist']."/".$record['name'], $record['list']);

                    $rm->RegistryWrite($record['id'], 'mailscanner', 'blacklist', 'str', $csr);

                    $msg .= "MailScanner Blacklist for ".$record['name']." created<br>";
                }
            }
        }

        $query = "SELECT d.id, d.name, w.list ".
            "FROM mailscanner_whitelist AS w ".
            "INNER JOIN domains AS d ON w.master_id = d.id ".
            "ORDER BY d.name ASC";

        $connection = $master_em->getConnection();
        $statement = $connection->prepare($query);
        $result = $statement->execute();

        if ($result) {
            $records = $statement->fetchAll();

            foreach ($records as $record) {
                $csr = hash('md5', $record['list']); // remote checksum
                $csl = $rm->RegistryRead($record['id'], 'mailscanner', 'whitelist', 'str'); // local checksum

                if ($csl) { // update
                    if (($csr != $csl) || $force) { // update
                        file_put_contents($config['path']['whitelist']."/".$record['name'], $record['list']);
                        
                        $rm->RegistryWrite($record['id'], 'mailscanner', 'whitelist', 'str', $csr);
                        
                        $msg .= "MailScanner Whitelist for ".$record['name']." updated<br>";
                    } else {
                        $msg .= "MailScanner Whitelist for ".$record['name']." already up2date<br>";
                    }
                } else { // insert
                    file_put_contents($config['path']['whitelist']."/".$record['name'], $record['list']);

                    $rm->RegistryWrite($record['id'], 'mailscanner', 'whitelist', 'str', $csr);

                    $msg .= "MailScanner Whitelist for ".$record['name']." created<br>";
                }
            }
        }

        // generate mailscanner config file content (conf.d)
        // - process rules
        // -- get checksums (rule)
        // -- write mailscanner config file (rule)
        // - get checksums (conf.d)
        // - write mailscanner config file (conf.d)
        $mailscanner_config = "";

        foreach($rules as $rule) {
            // mailscanner_config 
            $mailscanner_config .= $rule->getName()." = %rules-dir%/".$rule->getFilename()."\n";
        
            // process rule
            $rule_config = "";
        
            $query = "SELECT d.name, ms.value ".
                "FROM mailscanner AS ms ".
                "INNER JOIN domains AS d ON ms.domain_id = d.id ".
                "INNER JOIN rules AS r ON ms.rule_id = r.id ".
                "WHERE r.name = :rulename ".
                "ORDER BY d.name ASC, r.name ASC";

            // query master server
            $connection = $master_em->getConnection();
            $statement = $connection->prepare($query);
            $statement->bindValue('rulename', $rule->getName());
            $result = $statement->execute();
        
            if ($result) {
                $records = $statement->fetchAll();

                foreach ($records as $record) {
                    $rule_config .= "To:\t".$record['name']."\t".$record['value']."\n";
                }
            }

            $rule_config .= "FromOrTo:\tdefault\t".$rule->getDefault();
        
            $csr = hash('md5', $rule_config); // remote checksum
            $csl = $rm->RegistryRead($rule->getId(), 'mailscanner', 'checksum', 'str'); // local checksum

            if ($csl) { // update
                if (($csr != $csl) || $force) { // update
                    file_put_contents($config['path']['rules']."/".$rule->getFilename(), $rule_config);
                    
                    $rm->RegistryWrite($rule->getId(), 'mailscanner', 'checksum', 'str', $csr);

                    $msg .= "MailScanner Rule ".$rule->getFilename()." updated<br>";
                } else {
                    $msg .= "MailScanner Rule ".$rule->getFilename()." already up2date<br>";
                }
            } else { // insert
                file_put_contents($config['path']['rules']."/".$rule->getFilename(), $rule_config);

                $rm->RegistryWrite($rule->getId(), 'mailscanner', 'checksum', 'str', $csr);

                $msg .= "MailScanner Rule ".$rule->getFilename()." created<br>";
            }
        } // rules
        
        $csr = hash('md5', $mailscanner_config); // remote checksum
        $csl = $rm->SystemRead('mailscanner', 'checksum', 'str'); // local checksum

        if ($csl) { // update
            if (($csr != $csl) || $force) { // update
                file_put_contents($config['files']['mailscanner_rules'], $mailscanner_config);

                $rm->SystemWrite('mailscanner', 'checksum', 'str', $csr);

                $msg .= "MailScanner Config updated<br>\n";
            } else {
                $msg .= "MailScanner Config already up2date<br>\n";
            }
        } else { // insert
            file_put_contents($config['files']['mailscanner_rules'], $mailscanner_config);

            $rm->SystemWrite('mailscanner', 'checksum', 'str', $csr);

            $msg .= "MailScanner Config created<br>\n";
        }

        return $msg;
    }

    /**
     * @Route("/check-ms", name="check_ms")
     * @Template("jonasartsMailgatewaySyncBundle:Default:sync.html.twig")
     */
    public function checkMailScannerAction()
    {
        $config = $this->getConfig();

        $msg = $this->checkMailScanner($config);

        return array('msg' => $msg);
    }

    /**
     * crontab: *|5 * * * * root curl -k -s -o /dev/null http://<domain>/sync-ms
     * 
     * @Route("/sync-ms", name="sync_ms")
     * @Template("jonasartsMailgatewaySyncBundle:Default:sync.html.twig")
     */
    public function syncMailScannerAction()
    {
        $config = $this->getConfig();
        
        $request = $this->getRequest();
        
        $silent = true;
        $param = $request->query->get('silent');
        if ($param != null) {
            $silent = (bool)$param == true;
        }

        $force = (bool)$request->query->get('force') == true;

        $msg = $this->syncMailScanner($config, $force);

        $msg = "MailScanner Sync @ " . date('Y-m-d H:i:s') .
            " on " . $request->getHost() .
            $msg;

        return array('msg' => $msg);
    }
}
