<?php

namespace Connections\Controller;

use Auth\Model\User as UserModel;
use Connections\Model\Authentication;
use Workarea\Model\ConnectionType;
use Workarea\Model\ConnectionTypeField;
use Workarea\Model\Identifiers;
use Workarea\Model\UserConnection;
use Workarea\Model\UserConnectionsTable;
use Workarea\Model\UserConnectionDetails;
use Drone\Db\TableGateway\EntityAdapter;
use Drone\Db\TableGateway\TableGateway;
use Drone\Dom\Element\Form;
use Drone\Mvc\AbstractionController;
use Drone\Network\Http;
use Drone\Validator\FormValidator;
use Utils\Model\Entity as EntityMd;
use Drone\Error\Errno;

class Tools extends AbstractionController
{
    use \Drone\Error\ErrorTrait;

    /**
     * @var integer
     */
    private $identity;

    /**
     * @var EntityAdapter
     */
    private $usersEntity;

    /**
     * @var EntityAdapter
     */
    private $identifiersEntity;

    /**
     * @var EntityAdapter
     */
    private $connectionTypesEntity;

    /**
     * @var EntityAdapter
     */
    private $connectionFieldsEntity;

    /**
     * @var EntityAdapter
     */
    private $userConnectionEntity;

    /**
     * @var EntityAdapter
     */
    private $userConnectionDetailsEntity;

    /**
     * @return integer
     */
    private function getIdentity()
    {
        $config = include 'module/Auth/config/user.config.php';
        $method = $config["authentication"]["method"];
        $key    = $config["authentication"]["key"];

        switch ($method)
        {
            case '_COOKIE':

                $user = $this->getUsersEntity()->select([
                    "USERNAME" => $_COOKIE[$key]
                ]);

                break;

            case '_SESSION':

                $user = $this->getUsersEntity()->select([
                    "USERNAME" => $_SESSION[$key]
                ]);

                break;
        }

        $user = array_shift($user);

        return $user->USER_ID;
    }

    /**
     * @return UsersEntity
     */
    private function getUsersEntity()
    {
        if (!is_null($this->usersEntity))
            return $this->usersEntity;

        $this->usersEntity = new EntityAdapter(new TableGateway(new UserModel()));

        return $this->usersEntity;
    }

    /**
     * @return UsersEntity
     */
    private function getIdentifiersEntity()
    {
        if (!is_null($this->identifiersEntity))
            return $this->identifiersEntity;

        $this->identifiersEntity = new EntityAdapter(new TableGateway(new Identifiers()));

        return $this->identifiersEntity;
    }

    /**
     * @return EntityAdapter
     */
    private function getConnectionTypesEntity()
    {
        if (!is_null($this->connectionTypesEntity))
            return $this->connectionTypesEntity;

        $this->connectionTypesEntity = new EntityAdapter(new TableGateway(new ConnectionType()));

        return $this->connectionTypesEntity;
    }

    /**
     * @return EntityAdapter
     */
    private function getConnectionFieldsEntity()
    {
        if (!is_null($this->connectionFieldsEntity))
            return $this->connectionFieldsEntity;

        $this->connectionFieldsEntity = new EntityAdapter(new TableGateway(new ConnectionTypeField()));

        return $this->connectionFieldsEntity;
    }

    /**
     * @return EntityAdapter
     */
    private function getUserConnectionEntity()
    {
        if (!is_null($this->userConnectionEntity))
            return $this->userConnectionEntity;

        $this->userConnectionEntity = new EntityAdapter(new UserConnectionsTable(new UserConnection()));

        return $this->userConnectionEntity;
    }

    /**
     * @return EntityAdapter
     */
    private function getUserConnectionDetailsEntity()
    {
        if (!is_null($this->userConnectionDetailsEntity))
            return $this->userConnectionDetailsEntity;

        $this->userConnectionDetailsEntity = new EntityAdapter(new TableGateway(new UserConnectionDetails()));

        return $this->userConnectionDetailsEntity;
    }

    /**
     * Tests a connection
     *
     * @return array
     */
    public function testConnection()
    {
        clearstatcache();
        session_write_close();

        # data to send
        $data = [];

        # environment settings
        $post = $this->getPost();           # catch $_POST
        $this->setTerminal(true);           # set terminal

        # TRY-CATCH-BLOCK
        try {

            # STANDARD VALIDATIONS [check method]
            if (!$this->isPost())
            {
                $http = new Http();
                $http->writeStatus($http::HTTP_METHOD_NOT_ALLOWED);

                die('Error ' . $http::HTTP_METHOD_NOT_ALLOWED .' (' . $http->getStatusText($http::HTTP_METHOD_NOT_ALLOWED) . ')!!');
            }

            $idenfiers = $this->getIdentifiersEntity()->select([]);
            $dbconfig = [];

            if (array_key_exists('conn_id', $post))
            {
                # STANDARD VALIDATIONS [check needed arguments]
                $needles = ['conn_id'];

                array_walk($needles, function(&$item) use ($post) {
                    if (!array_key_exists($item, $post))
                    {
                        $http = new Http();
                        $http->writeStatus($http::HTTP_BAD_REQUEST);

                        die('Error ' . $http::HTTP_BAD_REQUEST .' (' . $http->getStatusText($http::HTTP_BAD_REQUEST) . ')!!');
                    }
                });

                $id = $post["conn_id"];

                $details = $this->getUserConnectionDetailsEntity()->select([
                    "USER_CONN_ID" => $id
                ]);

                foreach ($details as $field)
                {
                    foreach ($idenfiers as $identifier)
                    {
                        if ($field->CONN_IDENTI_ID == $identifier->CONN_IDENTI_ID)
                            $dbconfig[$identifier->CONN_IDENTI_NAME] = $field->FIELD_VALUE;
                    }
                }
            }
            else
            {
                # STANDARD VALIDATIONS [check needed arguments]
                $needles = ['type', 'aliasname'];

                array_walk($needles, function(&$item) use ($post) {
                    if (!array_key_exists($item, $post))
                    {
                        $http = new Http();
                        $http->writeStatus($http::HTTP_BAD_REQUEST);

                        die('Error ' . $http::HTTP_BAD_REQUEST .' (' . $http->getStatusText($http::HTTP_BAD_REQUEST) . ')!!');
                    }
                });

                $components = [
                    "attributes" => [
                        "type" => [
                            "required"  => true,
                        ],
                        "aliasname" => [
                            "required"  => true,
                        ]
                    ],
                ];

                $options = [
                    "type" => [
                        "label" => "Value of connection parameter"
                    ],
                    "aliasname" => [
                        "label" => "Type of connection parameter"
                    ]
                ];

                $form = new Form($components);
                $form->fill($post);

                $validator = new FormValidator($form, $options);
                $validator->validate();

                $data["validator"] = $validator;

                # STANDARD VALIDATIONS [check argument constraints]
                if (!$validator->isValid())
                {
                    $data["messages"] = $validator->getMessages();
                    throw new \Drone\Exception\Exception("Form validation errors");
                }

                $id = 0;

                foreach ($post['field'][$post["type"]] as $field_number => $field_value)
                {
                    foreach ($idenfiers as $identifier)
                    {
                        if ($field_number == $identifier->CONN_IDENTI_ID)
                            $dbconfig[$identifier->CONN_IDENTI_NAME] = $field_value;
                    }
                }
            }

            try
            {
                $entity = new EntityMd([]);
                $entity->setConnectionIdentifier("CONN" . $id);

                $driverAdapter = new \Drone\Db\Driver\DriverAdapter($dbconfig, false);
                $driverAdapter->getDb()->connect();
            }
            catch (\Exception $e)
            {
                # SUCCESS-MESSAGE
                $data["process"] = "error";
                $data["message"] = $e->getMessage();

                return $data;
            }

            # SUCCESS-MESSAGE
            $data["process"] = "success";
        }
        catch (\Drone\Exception\Exception $e)
        {
            # ERROR-MESSAGE
            $data["process"] = "warning";
            $data["message"] = $e->getMessage();
        }
        catch (\Exception $e)
        {
            $file = str_replace('\\', '', __CLASS__);
            $storage = new \Drone\Exception\Storage("cache/$file.json");

            # stores the error code
            if (($errorCode = $storage->store($e)) === false)
            {
                $errors = $storage->getErrors();

                # if error storing is not possible, handle it (internal app error)
                $this->handleErrors($errors, __METHOD__);
            }

            $data["code"]    = $errorCode;
            $data["message"] = $e->getMessage();

            $config = include 'config/application.config.php';
            $data["dev_mode"] = $config["environment"]["dev_mode"];

            # redirect view
            $this->setMethod('error');

            return $data;
        }

        return $data;
    }

    /**
     * Puts a worksheet
     *
     * @return array
     */
    public function worksheet()
    {
        # STANDARD VALIDATIONS [check method]
        if (!$this->isPost())
        {
            $http = new Http();
            $http->writeStatus($http::HTTP_METHOD_NOT_ALLOWED);

            die('Error ' . $http::HTTP_METHOD_NOT_ALLOWED .' (' . $http->getStatusText($http::HTTP_METHOD_NOT_ALLOWED) . ')!!');
        }

        # data to send
        $data = [];

        $this->setTerminal(true);           # set terminal
        $post = $this->getPost();           # catch $_POST

        $data["id"]   = $post["id"];
        $data["conn"] = $post["conn"];

        return $data;
    }

    /**
     * Executes a statement
     *
     * @return array
     */
    public function execute()
    {
        clearstatcache();
        session_write_close();

        # data to send
        $data = [];

        # environment settings
        $post = $this->getPost();           # catch $_POST
        $this->setTerminal(true);           # set terminal

        # TRY-CATCH-BLOCK
        try {

            # STANDARD VALIDATIONS [check method]
            if (!$this->isPost())
            {
                $http = new Http();
                $http->writeStatus($http::HTTP_METHOD_NOT_ALLOWED);

                die('Error ' . $http::HTTP_METHOD_NOT_ALLOWED .' (' . $http->getStatusText($http::HTTP_METHOD_NOT_ALLOWED) . ')!!');
            }

            # STANDARD VALIDATIONS [check needed arguments]
            $needles = ['conn', 'worksheet'];

            array_walk($needles, function(&$item) use ($post) {
                if (!array_key_exists($item, $post))
                {
                    $http = new Http();
                    $http->writeStatus($http::HTTP_BAD_REQUEST);

                    die('Error ' . $http::HTTP_BAD_REQUEST .' (' . $http->getStatusText($http::HTTP_BAD_REQUEST) . ')!!');
                }
            });

            $data["worksheet"] = $post["worksheet"];

            $id = $post["conn"];
            $data["conn"] = $id;

            $connection = $this->getUserConnectionEntity()->select([
                "USER_CONN_ID" => $id
            ]);

            if (!count($connection))
                throw new \Exception("The Connection does not exists");

            $connection = array_shift($connection);

            if ($connection->STATE == 'I')
                throw new \Drone\Exception\Exception("This connection was deleted", 300);

            $details = $this->getUserConnectionDetailsEntity()->select([
                "USER_CONN_ID" => $id
            ]);

            $idenfiers = $this->getIdentifiersEntity()->select([]);

            $dbconfig = [];

            foreach ($details as $field)
            {
                foreach ($idenfiers as $identifier)
                {
                    if ($field->CONN_IDENTI_ID == $identifier->CONN_IDENTI_ID)
                        $dbconfig[$identifier->CONN_IDENTI_NAME] = $field->FIELD_VALUE;
                }
            }

            /* identifies if sql is base64 encoded */
            if (array_key_exists('base64', $post))
            {
                if ((bool) $post["base64"])
                    $post["sql"] = base64_decode($post["sql"]);
            }

            $data["sql"] = base64_encode($post["sql"]);

            $sql_text = $post["sql"];

            /*
             * SQL parsing
             */
            $sql_text = trim($sql_text);

            if (empty($sql_text))
                throw new \Drone\Exception\Exception("Empty statement");

            $pos = strpos($sql_text, ';');

            if ($pos !== false)
            {
                $end_stament = strstr($sql_text, ';');

                if ($end_stament == ';')
                    $sql_text = strstr($sql_text, ';', true);
            }

             # clean comments and other characters

            // (/**/)
            $clean_code = preg_replace('/(\s)*\/\*([^*]|[\r\n]|(\*+([^*\/]|[\r\n])))*\*+\//', '', $sql_text);

            // (--)
            $clean_code = preg_replace('/(\s)*--.*\n/', "", $clean_code);

            # clean other characters starting senteces
            $clean_code = preg_replace('/^[\n\t\s]*/', "", $clean_code);

            # indicates if SQL is a selection statement
            $isSelectStm = $data["selectStm"] = (preg_match('/^SELECT/i', $clean_code));

            # indicates if SQL is a show statement
            $isShowStm   = $data["showStm"]   = (preg_match('/^SHOW/i', $clean_code));

            # detect selection
            if ($isSelectStm || $isShowStm)
            {
                $step = 10;

                $row_start = 0;
                $row_end   = $step;

                if (array_key_exists('row_start', $post) && array_key_exists('row_end', $post))
                {
                    $components = [
                        "attributes" => [
                            "row_start" => [
                                "required" => true,
                                "type"     => "number",
                                "min"      => 0
                            ],
                            "row_end" => [
                                "required" => true,
                                "type"     => "number",
                                "min"      => 0
                            ],
                        ],
                    ];

                    $options = [
                        "row_start" => [
                            "label" => "Start row",
                        ],
                        "row_end" => [
                            "label" => "End row",
                        ],
                    ];

                    $form = new Form($components);
                    $form->fill($post);

                    $validator = new FormValidator($form, $options);
                    $validator->validate();

                    # STANDARD VALIDATIONS [check argument constraints]
                    if (!$validator->isValid())
                    {
                        $http = new Http();
                        $http->writeStatus($http::HTTP_BAD_REQUEST);

                        die('Error ' . $http::HTTP_BAD_REQUEST .' (' . $http->getStatusText($http::HTTP_BAD_REQUEST) . ')!!');
                    }

                    $row_start = $post["row_start"] + $step;
                    $row_end   = $post["row_end"] + $step;
                }

                switch (strtolower($dbconfig["driver"]))
                {
                    case 'mysqli':

                        # show statement cannot be a subquery
                        if (!$isShowStm)
                            $sql_text = "SELECT (@ROW_NUM:=@ROW_NUM + 1) AS ROW_NUM, V.* FROM (
                                            " . $sql_text . "
                                        ) V LIMIT $row_start, $step";
                        break;

                    case 'oci8':

                        $start = $row_start + 1;

                        $sql_text = "SELECT * FROM (
                                        SELECT ROWNUM ROW_NUM, V.* FROM (" . $sql_text . ") V
                                    ) VV WHERE VV.ROW_NUM BETWEEN $start AND $row_end";
                        break;

                    case 'sqlsrv':

                        $start = $row_start + 1;

                        $sql_text = "SELECT VV.*
                                    FROM (
                                        SELECT ROW_NUMBER() OVER(ORDER BY (
                                            SELECT TOP 1 NAME
                                            FROM SYS.DM_EXEC_DESCRIBE_FIRST_RESULT_SET('$sql_text', NULL, 0))
                                        ) AS ROW_NUM, V.*
                                        FROM ( $sql_text ) V
                                    ) VV
                                    WHERE VV.ROW_NUM BETWEEN $start AND $row_end";
                        break;

                    default:
                        # code...
                        break;
                }

                $data["row_start"] = $row_start;
                $data["row_end"]   = $row_end;
            }

            try {

                $connError = false;

                $entity = new EntityMd([]);
                $entity->setConnectionIdentifier("CONN" . $id);

                $driverAdapter = new \Drone\Db\Driver\DriverAdapter($dbconfig, false);

                # start time to compute execution
                $startTime = microtime(true);

                $driverAdapter->getDb()->connect();

                $auth = $driverAdapter;

                $data["results"] = $auth->getDb()->execute($sql_text);
            }
            # encapsulate real connection error!
            catch (\Drone\Db\Driver\Exception\ConnectionException $e)
            {
                $connError = true;

                $file = str_replace('\\', '', __CLASS__);
                $storage = new \Drone\Exception\Storage("cache/$file.json");

                if (($errorCode = $storage->store($e)) === false)
                {
                    $errors = $storage->getErrors();
                    $this->handleErrors($errors, __METHOD__);
                }

                $data["code"]    = $errorCode;
                $data["message"] = "Could not connect to database!";

                # to identify development mode
                $config = include 'config/application.config.php';
                $data["dev_mode"] = $config["environment"]["dev_mode"];

                # redirect view
                $this->setMethod('error');
            }
            catch (\Exception $e)
            {
                # SUCCESS-MESSAGE
                $data["process"] = "error";
                $data["message"] = $e->getMessage();

                return $data;
            }

            # end time to compute execution
            $endTime = microtime(true);
            $elapsed_time = $endTime - $startTime;

            $data["time"] = round($elapsed_time, 4);

            if (!$connError)
            {
                $data["num_rows"]      = $auth->getDb()->getNumRows();
                $data["num_fields"]    = $auth->getDb()->getNumFields();
                $data["rows_affected"] = $auth->getDb()->getRowsAffected();

                # cumulative results
                if ($isSelectStm && array_key_exists('num_rows', $post) && array_key_exists('time', $post))
                {
                    $data["num_rows"] += $post["num_rows"];
                    $data["time"]     += $post["time"];
                }

                $data["data"] = [];

                # redirect view
                if ($isSelectStm || $isShowStm)
                {
                    $rows = $auth->getDb()->getArrayResult();

                    $k = 0;

                    # columns with errors in a select statement
                    $column_errors = [];

                    # data parsing
                    foreach ($rows as $key => $row)
                    {
                        $k++;

                        $data["data"][$key] = [];

                        if ($isShowStm)
                        {
                            $data["data"][$key]["ROW_NUM"] = $k;
                            $data["data"][$key][0] = $k;
                        }

                        foreach ($row as $column => $value)
                        {
                            if ($isShowStm)
                                $column++;

                            if (gettype($value) == 'object')
                            {
                                if  (get_class($value) == 'OCI-Lob')
                                {
                                    if (($val = @$value->load()) === false)
                                    {
                                        $val = null;   # only for default, this value is not used
                                        $column_errors[] = $column;
                                    }

                                    $data["data"][$key][$column] = $val;
                                }
                                else
                                    $data["data"][$key][$column] = $value;
                            }
                            else {
                                $data["data"][$key][$column] = $value;
                            }
                        }
                    }

                    $data["column_errors"] = $column_errors;

                    if ($row_start > 1)
                        $this->setMethod('nextResults');
                }

                if (array_key_exists('id', $post))
                    $data["id"] = $post["id"];

                # SUCCESS-MESSAGE
                $data["process"] = "success";
            }
        }
        catch (\Drone\Exception\Exception $e)
        {
            # ERROR-MESSAGE
            $data["process"] = "warning";
            $data["message"] = $e->getMessage();
        }
        catch (\Exception $e)
        {
            $file = str_replace('\\', '', __CLASS__);
            $storage = new \Drone\Exception\Storage("cache/$file.json");

            # stores the error code
            if (($errorCode = $storage->store($e)) === false)
            {
                $errors = $storage->getErrors();

                # if error storing is not possible, handle it (internal app error)
                $this->handleErrors($errors, __METHOD__);
            }

            $data["code"]    = $errorCode;
            $data["message"] = $e->getMessage();

            $config = include 'config/application.config.php';
            $data["dev_mode"] = $config["environment"]["dev_mode"];

            # redirect view
            $this->setMethod('error');

            return $data;
        }

        return $data;
    }

    /**
     * Exports a statement
     *
     * @return array
     */
    public function export()
    {
        clearstatcache();
        session_write_close();

        # data to send
        $data = [];

        # environment settings
        $post = $this->getPost();           # catch $_POST
        $this->setTerminal(true);           # set terminal

        # TRY-CATCH-BLOCK
        try {

            # STANDARD VALIDATIONS [check method]
            if (!$this->isPost())
            {
                $http = new Http();
                $http->writeStatus($http::HTTP_METHOD_NOT_ALLOWED);

                die('Error ' . $http::HTTP_METHOD_NOT_ALLOWED .' (' . $http->getStatusText($http::HTTP_METHOD_NOT_ALLOWED) . ')!!');
            }

            # STANDARD VALIDATIONS [check needed arguments]
            $needles = ['conn', 'sql', 'type', 'filename'];

            array_walk($needles, function(&$item) use ($post) {
                if (!array_key_exists($item, $post))
                {
                    $http = new Http();
                    $http->writeStatus($http::HTTP_BAD_REQUEST);

                    die('Error ' . $http::HTTP_BAD_REQUEST .' (' . $http->getStatusText($http::HTTP_BAD_REQUEST) . ')!!');
                }
            });

            $components = [
                "attributes" => [
                    "conn" => [
                        "required"  => true,
                        "type"      => "number"
                    ],
                    "sql" => [
                        "required"  => true,
                        "type"      => "text"
                    ],
                    "type" => [
                        "required"  => true,
                        "type"      => "text"
                    ],
                    "filename" => [
                        "required"  => true,
                        "type"      => "text"
                    ]
                ],
            ];

            $options = [
                "conn" => [
                    "label" => "Connection",
                ],
                "sql" => [
                    "label" => "SQL",
                    "validators" => [
                        "Regex" => ["pattern" => '/^[a-zA-Z0-9\+\/]+$/']
                    ]
                ],
                "type" => [
                    "label" => "Type",
                    "validators" => [
                        "InArray" => ["haystack" => ['excel', 'csv']]
                    ]
                ],
                "filename" => [
                    "label" => "Filename"
                ]
            ];

            $form = new Form($components);
            $form->fill($post);

            $validator = new FormValidator($form, $options);
            $validator->validate();

            $data["validator"] = $validator;

            # form validation
            if (!$validator->isValid())
            {
                $data["messages"] = $validator->getMessages();
                throw new \Drone\Exception\Exception("Form validation errors", 300);
            }

            $id = $post["conn"];

            $connection = $this->getUserConnectionEntity()->select([
                "USER_CONN_ID" => $id
            ]);

            if (!count($connection))
                throw new \Exception("The Connection does not exists");

            $connection = array_shift($connection);

            if ($connection->STATE == 'I')
                throw new \Drone\Exception\Exception("This connection was deleted", 300);

            $details = $this->getUserConnectionDetailsEntity()->select([
                "USER_CONN_ID" => $id
            ]);

            $idenfiers = $this->getIdentifiersEntity()->select([]);

            $dbconfig = [];

            foreach ($details as $field)
            {
                foreach ($idenfiers as $identifier)
                {
                    if ($field->CONN_IDENTI_ID == $identifier->CONN_IDENTI_ID)
                        $dbconfig[$identifier->CONN_IDENTI_NAME] = $field->FIELD_VALUE;
                }
            }

            /* sql post value muest be ever base64 encoded */
            $post["sql"] = base64_decode($post["sql"]);
            $data["sql"] = $post["sql"];

            $sql_text = $post["sql"];

            /*
             * SQL parsing
             */
            $sql_text = trim($sql_text);

            if (empty($sql_text))
                throw new \Drone\Exception\Exception("Empty statement");

            $pos = strpos($sql_text, ';');

            if ($pos !== false)
            {
                $end_stament = strstr($sql_text, ';');

                if ($end_stament == ';')
                    $sql_text = strstr($sql_text, ';', true);
            }

             # clean comments and other characters

            // (/**/)
            $clean_code = preg_replace('/(\s)*\/\*([^*]|[\r\n]|(\*+([^*\/]|[\r\n])))*\*+\//', '', $sql_text);

            // (--)
            $clean_code = preg_replace('/(\s)*--.*\n/', "", $clean_code);

            # clean other characters starting senteces
            $clean_code = preg_replace('/^[\n\t\s]*/', "", $clean_code);

            # indicates if SQL is a selection statement
            $isSelectStm = $data["selectStm"] = (preg_match('/^SELECT/i', $clean_code));

            # indicates if SQL is a show statement
            $isShowStm   = $data["showStm"]   = (preg_match('/^SHOW/i', $clean_code));

            # detect selection
            if (!$isSelectStm && !$isShowStm)
                throw new \Exception("You can't export a non-selection statement!");

            try {

                $connError = false;

                $entity = new EntityMd([]);
                $entity->setConnectionIdentifier("CONN" . $id);

                $driverAdapter = new \Drone\Db\Driver\DriverAdapter($dbconfig, false);

                # start time to compute execution
                $startTime = microtime(true);

                $driverAdapter->getDb()->connect();

                $auth = $driverAdapter;

                $data["results"] = $auth->getDb()->execute($sql_text);
            }
            # encapsulate real connection error!
            catch (\Drone\Db\Driver\Exception\ConnectionException $e)
            {
                $connError = true;

                $file = str_replace('\\', '', __CLASS__);
                $storage = new \Drone\Exception\Storage("cache/$file.json");

                # stores the error code
                if (($errorCode = $storage->store($e)) === false)
                {
                    $errors = $storage->getErrors();

                    # if error storing is not possible, handle it (internal app error)
                    $this->handleErrors($errors, __METHOD__);
                }

                $data["code"]    = $errorCode;
                $data["message"] = "Could not connect to database";

                # to identify development mode
                $config = include 'config/application.config.php';
                $data["dev_mode"] = $config["environment"]["dev_mode"];

                # redirect view
                $this->setMethod('error');
            }
            catch (\Exception $e)
            {
                # SUCCESS-MESSAGE
                $data["process"] = "error";
                $data["message"] = $e->getMessage();

                return $data;
            }

            # end time to compute execution
            $endTime = microtime(true);
            $elapsed_time = $endTime - $startTime;

            $data["time"] = round($elapsed_time, 4);

            if (!$connError)
            {
                $data["num_rows"]      = $auth->getDb()->getNumRows();
                $data["num_fields"]    = $auth->getDb()->getNumFields();
                $data["rows_affected"] = $auth->getDb()->getRowsAffected();

                $rows = $auth->getDb()->getArrayResult();

                # columns with errors in a select statement
                $column_errors = [];

                switch ($post["type"])
                {
                    case 'excel':
                        $ext = '.xls';
                        break;
                    case 'csv':
                        $ext = '.csv';
                        break;
                    default:
                        $ext = '.txt';
                        break;
                }

                $filename = $post["filename"] . $ext;

                $file_hd = @fopen("cache/" . $filename, "w+");

                if (!$file_hd)
                {
                    $this->error(Errno::FILE_PERMISSION_DENIED, "cache/" . $filename);
                    throw new \Exception("The file could not be created!");
                }

                $contents = "";

                $data["data"] = [];

                switch ($post["type"])
                {
                    case 'excel':

                        $table = "<html xmlns:v='urn:schemas-microsoft-com:vml' \r\n\txmlns:o='urn:schemas-microsoft-com:office:office'\r\n";
                        $table .= "\txmlns:x='urn:schemas-microsoft-com:office:excel'\r\n";
                        $table .= "\txmlns='http://www.w3.org/TR/REC-html40'>\r\n";

                        $table .= "<head>\r\n";
                        $table .= "\t<meta name='Excel Workbook Frameset'><meta http-equiv='Content-Type' content='text/html; charset='utf-8'>\r\n";
                        $table .= "</head>\r\n\r\n";

                        $table .= "<body>\r\n<table border=1>\r\n";

                        $column_names = [];

                        foreach ($rows[0] as $column_name => $row)
                        {
                            if (!is_numeric($column_name))
                                $column_names[] = $column_name;
                        }

                        $table .= "\t<thead>\r\n\t\t<tr>\r\n";

                        foreach ($column_names as $column_name)
                        {
                            $table .= "\t\t\t<th>$column_name</th>\r\n";
                        }

                        $table .= "\t\t</tr>\r\n\t</thead>\r\n\t<tbody>";

                        # data parsing
                        foreach ($rows as $key => $row)
                        {
                            $data["data"][$key] = [];

                            foreach ($row as $column => $value)
                            {
                                if ($isShowStm)
                                    $column++;

                                if (gettype($value) == 'object')
                                {
                                    if  (get_class($value) == 'OCI-Lob')
                                    {
                                        if (($val = @$value->load()) === false)
                                        {
                                            $val = null;   # only for default, this value is not used
                                            $column_errors[] = $column;
                                        }

                                        $data["data"][$key][$column] = $val;
                                    }
                                    else
                                        $data["data"][$key][$column] = $value;
                                }
                                else {
                                    $data["data"][$key][$column] = $value;
                                }
                            }

                        }

                        foreach ($data["data"] as $row)
                        {
                            $table .= "\t\t<tr>\r\n";

                            foreach ($column_names as $column_name)
                            {
                                $table .= "\t\t\t<td>". $row[$column_name] ."</td>\r\n";
                            }

                            $table .= "\t\t</tr>\r\n";
                        }

                        $table .= "\t</tbody>\r\n</table>\r\n</body>\r\n</html>";
                        $contents = $table;

                        break;

                    case 'csv':

                        $text = "";

                        $column_names = [];

                        foreach ($rows[0] as $column_name => $row)
                        {
                            if (!is_numeric($column_name))
                                $column_names[] = $column_name;
                        }

                        foreach ($column_names as $column_name)
                        {
                            $text .= "$column_name;";
                        }

                        $text .= "\r\n";

                        # data parsing
                        foreach ($rows as $key => $row)
                        {
                            $data["data"][$key] = [];

                            foreach ($row as $column => $value)
                            {
                                if ($isShowStm)
                                    $column++;

                                if (gettype($value) == 'object')
                                {
                                    if  (get_class($value) == 'OCI-Lob')
                                    {
                                        if (($val = @$value->load()) === false)
                                        {
                                            $val = null;   # only for default, this value is not used
                                            $column_errors[] = $column;
                                        }

                                        $data["data"][$key][$column] = $val;
                                    }
                                    else
                                        $data["data"][$key][$column] = $value;
                                }
                                else {
                                    $data["data"][$key][$column] = $value;
                                }
                            }
                        }

                        foreach ($data["data"] as $row)
                        {
                            foreach ($column_names as $column_name)
                            {
                                $text .= $row[$column_name] . ";";
                            }

                            $text .= "\r\n";
                        }

                        $contents = $text;

                        break;

                    default:
                        # code...
                        break;
                }

                if (!@fwrite($file_hd, $contents))
                {
                    $this->error(Errno::FILE_PERMISSION_DENIED, "cache/" . $filename);
                    throw new \Exception("The file could not be generated!");
                }

                @fclose($file_hd);

                $data["column_errors"] = $column_errors;

                $data["filename"] = $filename;

                if (array_key_exists('id', $post))
                    $data["id"] = $post["id"];

                # SUCCESS-MESSAGE
                $data["process"] = "success";
            }
        }
        catch (\Drone\Exception\Exception $e)
        {
            # ERROR-MESSAGE
            $data["process"] = "warning";
            $data["message"] = $e->getMessage();
        }
        catch (\Exception $e)
        {
            $file = str_replace('\\', '', __CLASS__);
            $storage = new \Drone\Exception\Storage("cache/$file.json");

            # stores the error code
            if (($errorCode = $storage->store($e)) === false)
            {
                $errors = $storage->getErrors();

                # if error storing is not possible, handle it (internal app error)
                $this->handleErrors($errors, __METHOD__);
            }

            # errors retrived by the use of ErrorTrait
            if (count($this->getErrors()))
                $this->handleErrors($this->getErrors(), __METHOD__);

            $data["code"]    = $errorCode;
            $data["message"] = $e->getMessage();

            $config = include 'config/application.config.php';
            $data["dev_mode"] = $config["environment"]["dev_mode"];

            # redirect view
            $this->setMethod('error');

            return $data;
        }

        return $data;
    }

    private function handleErrors(Array $errors, $method)
    {
        if (count($errors))
        {
            $errorInformation = "";

            foreach ($errors as $errno => $error)
            {
                $errorInformation .=
                    "<strong style='color: #a94442'>".
                        $method
                            . "</strong>: <span style='color: #e24f4c'>{$error}</span> \n<br />";
            }

            $hd = @fopen('cache/errors.txt', "a");

            if (!$hd || !@fwrite($hd, $errorInformation))
            {
                # error storing are not mandatory!
            }
            else
                @fclose($hd);

            $config = include 'config/application.config.php';
            $dev = $config["environment"]["dev_mode"];

            if ($dev)
                echo $errorInformation;
        }
    }
}