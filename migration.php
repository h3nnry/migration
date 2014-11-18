<?php

class Migration{

    //Parametrii conexiunii cu baza veche
    private static $connection_1 = array('host'=>'127.0.0.1', 'user'=>'root','pass'=>'1', 'dbname'=>'egov');
    //Parametrii conexiunii cu baza noua
    private static $connection_2 = array('host'=>'127.0.0.1', 'user'=>'root','pass'=>'1', 'dbname'=>'minimal');

    //Aici se defineste masivul cu tabelele pe care doriti sa le importati. In caz ca doriti sa importati toate tabelele, stergeti toate valorile din masiv
    private static $commonTables = array();
    // "sys_Clients","sys_Companies","sys_ContactUser","sys_GeneralValues",
    //     "sys_MainConfig","sys_TranslateValues","tbl_LoginType","tbl_Priorities","tbl_RelationUserRole",
    //     "tbl_Translation","tbl_User","tbl_UserCertificate","tbl_config_module","tbl_config_setting_list",
    //     "tbl_config_setting_value","usr_Group","usr_UserGroup"

    //Aici se definesc masivul cu tabele si cimpurile din baza veche si cimpurile din baza noua in care se va copia informatia
    private static $oldNewFields = array();
    //array('sys_Temporary_Break_Users'=>array('replacer_id'=>'new_replacer_id', 'random_col'=>'new_rand_col'));

    private static $differentTables = array();

    private static $finalTables = array();
    private static $differentColumnsTables = array();
    private static $oldAllTables = array();
    private static $newAllTables = array();
    private static $differentColumns;
    private static $finalSql;
    private static $sqlInsert;
    private static $contor;

    private $host;
    private $user;
    private $pass;
    public $dbname;

    private $dbh;
    private $error;

    private $stmt;

    public function __construct($ihost, $iuser, $ipass, $idbname){
        //Setare valori conexiune
        $this->host = $ihost;
        $this->user = $iuser;
        $this->pass = $ipass;
        $this->dbname = $idbname;

        // Setare DSN
        $dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->dbname;
        // Setare optiuni
        $options = array(
            PDO::ATTR_PERSISTENT    => true,
            PDO::ATTR_ERRMODE       => PDO::ERRMODE_EXCEPTION
        );
        // Creare noua instanta PDO
        try{
            $this->dbh = new PDO($dsn, $this->user, $this->pass, $options);
        }
            // Prelucram erorile
        catch(PDOException $e){
            print "Error!: " . $e->getMessage() . "<br/>";
            die();
        }
    }

    public static function createOldConnection(){
        $connection = new self(self::$connection_1['host'], self::$connection_1['user'], self::$connection_1['pass'], self::$connection_1['dbname']);
        return $connection;
    }

    public static function createNewConnection(){
        $connection = new self(self::$connection_2['host'], self::$connection_2['user'], self::$connection_2['pass'], self::$connection_2['dbname']);
        return $connection;
    }

    public function query($query){
        $this->stmt = $this->dbh->prepare($query); //,array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY)
    }

    public function execute(){
        return $this->stmt->execute();
    }

    public function executeParam($values){
        return $this->stmt->execute($values);
    }

    public function resultset(){
        $this->execute();
        return $this->stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllTables() {
        $tableList = array();
        $result = $this->dbh->query("SHOW FULL TABLES WHERE TABLE_TYPE != 'VIEW'");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tableList[] = $row[0];
        }
        // print_r($tableList);
        return $tableList;
    }

    public function getTableColumns($tableName){
        $allColumns = array();

        $result = $this->dbh->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = '".$this->dbname."' AND TABLE_NAME = '".$tableName."'");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $allColumns[] = $row[0];
        }

        return $allColumns;
    }

    public static function returnOldTables(){
        return Migration::createOldConnection()->getAllTables();
    }

    public static function returnNewTables(){
        return Migration::createNewConnection()->getAllTables();
    }

    public static function selectInitialTables(){
        //self::$commonTables = Migration::tableReturn();
        if(count(self::$commonTables) == 0){
            self::$commonTables = array_intersect(self::$oldAllTables, self::$newAllTables);
            self::$differentTables = array_diff(self::$oldAllTables, self::$newAllTables);
        }
    }

    public static function searchDifferentColumns($oldDatabase, $newDatabase){
        foreach (self::$commonTables as $key => $value) {
            if(( count(array_diff($oldTableColumns = $oldDatabase->getTableColumns($value), $newTableColumns = $newDatabase->getTableColumns($value))) == 0 )) {
                if(($value!='sys_ActionHistory'))
                    self::$finalTables[] = $value;
            }
            else {
                self::$differentColumns = array_diff($oldTableColumns = $oldDatabase->getTableColumns($value), $newTableColumns = $newDatabase->getTableColumns($value));
                foreach (self::$differentColumns as $key => $val1) {
                    self::$differentColumnsTables[$value][] = $val1;
                }
            }

        }
    }

    public static function insertQuery($connection){
        $connection->query("set foreign_key_checks=0");
        $connection->execute();
        self::$finalSql = substr(self::$finalSql, 0, -2);
        self::$sqlInsert .= self::$finalSql;
        $connection->query(self::$sqlInsert);
        $connection->execute();
        $connection->query("set foreign_key_checks=1");
        $connection->execute();
    }

    public static function initMigration(){
        $oldDatabase = Migration::createOldConnection();
        self::$oldAllTables = Migration::returnOldTables();

        $newDatabase = Migration::createNewConnection();
        self::$newAllTables = Migration::returnNewTables();

        self::selectInitialTables();

        self::searchDifferentColumns($oldDatabase, $newDatabase);

        $k=0;
        foreach (self::$commonTables as $keyTable => $table) {

            if($table != 'sys_ActionHistory'){
                print_r("Inserted in ".$table."\n");

                if((is_array(self::$oldNewFields)) && (count(self::$oldNewFields)>0) && (in_array($table, array_keys(self::$oldNewFields)))){
                    $selectDifferentName = array_keys(self::$oldNewFields[$table]);
                    $excludeColumns = array();
                    foreach (self::$differentColumnsTables as $key => $val1) {
                        if($key == $table){
                            foreach ($val1 as $key => $val2) {
                                $excludeColumns[] = "'".$val2."'";
                            }
                        }
                    }
                    $excludeColumnsFinal = array();
                    foreach (self::$differentColumnsTables as $key => $val1) {
                        if($key == $table){
                            foreach ($val1 as $key => $val2) {
                                $excludeColumnsFinal[] = "'".$val2."'";
                            }
                        }
                    }

                    $querry  = "SELECT `COLUMN_NAME` FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA ='".$oldDatabase->dbname."' AND TABLE_NAME='".$table."' AND COLUMN_NAME NOT IN (".implode(',', $excludeColumns).")";
                    $oldDatabase->query($querry);
                    $getColumns = $oldDatabase->resultset();
                    $finalColumns = array();
                    foreach ($getColumns as $key => $val2) {
                        $finalColumns[] = "`".$val2['COLUMN_NAME']."`";
                    }
                    if(is_array($selectDifferentName))
                        $finalColumns = array_merge($finalColumns, $selectDifferentName);
                    $finalQuery = "SELECT ".implode(',', $finalColumns)." FROM ".$table;
                    $oldDatabase->query($finalQuery);
                }
                elseif((in_array($table, array_diff(self::$commonTables, self::$finalTables))) && ($table != 'sys_ActionHistory')){
                    $excludeColumns = array();
                    foreach (self::$differentColumnsTables as $key => $val1) {
                        if($key == $table){
                            foreach ($val1 as $key => $val2) {
                                $excludeColumns[] = "'".$val2."'";
                            }
                        }
                    }
                    $excludeColumnsFinal = array();
                    foreach (self::$differentColumnsTables as $key => $val1) {
                        if($key == $table){
                            foreach ($val1 as $key => $val2) {
                                $excludeColumnsFinal[] = "'".$val2."'";
                            }
                        }
                    }

                    $querry  = "SELECT `COLUMN_NAME` FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA ='".$oldDatabase->dbname."' AND TABLE_NAME='".$table."' AND COLUMN_NAME NOT IN (".implode(',', $excludeColumns).")";
                    $oldDatabase->query($querry);
                    $getColumns = $oldDatabase->resultset();
                    $finalColumns = array();
                    foreach ($getColumns as $key => $val2) {//die(var_dump($val2));
                        $finalColumns[] = "`".$val2['COLUMN_NAME']."`";
                    }
                    // die(var_dump(implode(',', $finalColumns)));
                    $finalQuery = "SELECT ".implode(',', $finalColumns)." FROM ".$table;
                    $oldDatabase->query($finalQuery);

                } else {
                    $oldDatabase->query("SELECT * FROM ".$table);
                }

                $selectedData = $oldDatabase->resultset();

                $newDatabase->query("set foreign_key_checks=0");
                $newDatabase->execute();
                $newDatabase->query("TRUNCATE TABLE ".$table);
                $newDatabase->execute();
                $newDatabase->query("set foreign_key_checks=1");
                $newDatabase->execute();
                self::$finalSql = "(";
                $placeholder = "";
                $i=0;
                $j=0;
                self::$contor = 0;
                if(count($selectedData)>0){
                    foreach ($selectedData as $key => $val) {

                        //Creem string-ul cu denumirea la field-uri
                        $buildFields = '';
                        if($i<1){
                            if (is_array(array_keys($val))) {
                                foreach(array_keys($val) as $key => $field) {
                                    if(in_array($table, array_keys(self::$oldNewFields))){
                                        if ($key == 0) {
                                            $buildFields .= '`'.$field.'`';
                                        } elseif(in_array($field, array_keys(self::$oldNewFields[$table]))) {
                                            $buildFields .= ', '.'`'.array_values(self::$oldNewFields[$table])[$j].'`';
                                            $j++;
                                        } else {
                                            $buildFields .= ', '.'`'.$field.'`';
                                        }
                                    } else {
                                        if ($key == 0) {
                                            $buildFields .= '`'.$field.'`';
                                        } else {
                                            $buildFields .= ', '.'`'.$field.'`';
                                        }
                                    }
                                }
                            }
                            self::$sqlInsert = 'INSERT INTO '.$table.' ('.$buildFields.') VALUES ';
                            $i++;
                        }

                        $buildValues2 = '';
                        if (is_array(array_values($val))) {
                            foreach(array_values($val) as $key => $value) {
                                $buildValues2 .= "'".mysql_escape_string($value)."',";
                            }
                        }
                        $buildValues2 = substr($buildValues2, 0, -1);
                        self::$finalSql .= $buildValues2."),(";
                        self::$contor++;
                    }
                }
                if(count($selectedData)!=0){
                    $my_file = 'Migration.log';
                    if($k == 0){
                        $handle = fopen($my_file, 'w') or die('Imposibil de deschis fisierul:  '.$my_file);
                    }
                    elseif($k!=0){
                        $handle = fopen($my_file, 'a') or die('Imposibil de deschis fisierul:  '.$my_file);
                    }
                    $new_data = 'In tabela '.$table.' au fost inserate '.self::$contor.' inregistrari'."\n";
                    fwrite($handle, $new_data);
                    fclose($handle);
                    $k++;
                    Migration::insertQuery($newDatabase);
                }
            }

        }

    }

}

Migration::initMigration();

?>
