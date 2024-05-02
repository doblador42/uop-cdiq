<?php

// TODO maybe add error codes on the inerfaces for better responses and return values
// public const ERROR_XYZ = 1;

date_default_timezone_set("UTC");

enum Update {
	case SECRETARY_ADD_INTERVIEWEE;
	case SECRETARY_DELETE_INTERVIEWEE; // TODO needs update after SECRETARY_ENQUEUE
	case SECRETARY_ENQUEUE; // TODO
	case SECRETARY_ENQUEUED_TO_DEQUEUED; // TODO
	case SECRETARY_ACTIVE_TO_INACTIVE_INTERVIEWEE; // TODO
	case SECRETARY_INACTIVE_TO_ACTIVE_INTERVIEWEE; // TODO
	case SECRETARY_ADD_INTERVIEWER; // TODO
	case SECRETARY_REMOVE_INTERVIEWER; // TODO
	
	case SYSTEM_ENQUEUED_TO_CALLING; // TODO // checks and updates if needed // maybe do this after any update
	case SYSTEM_CALLING_TO_DESICION; // TODO // checks and updates if needed

	case GATEKEEPER_ACTIVE_TO_INACTIVE_INTERVIEWER; // TODO // MANAGER_AVAILABLE_PAUSE & MANAGER_CALLING_PAUSE
	case GATEKEEPER_INACTIVE_TO_ACTIVE_INTERVIEWER; // TODO
	case GATEKEEPER_CALLING_TO_HAPPENING; // TODO
	case GATEKEEPER_CALLING_TO_DEQUEUED; // TODO
	case GATEKEEPER_DESICION_TO_HAPPENING; // TODO
	case GATEKEEPER_DESICION_TO_DEQUEUED; // TODO
	case GATEKEEPER_HAPPENING_TO_COMPLETED; // TODO // + GATEKEEPER_HAPPENING_TO_COMPLETED_AND_ACTIVE_TO_INACTIVE_INTERVIEWER; // unecessary since you can active/inactive before completion if you want?
}

class UpdateArguments {
	public readonly int $update_id_known;
	public readonly int | null $iwee_id;
	public readonly string | null $iwee_email;
	public readonly array | null $iwer_id;
	public readonly int | null $iw_id;

	public function __construct(
		int $update_id_known,
		int | null $iwee_id = null,
		string | null $iwee_email = null,
		array | null $iwer_id = null,
		int | null $iw_id = null
	) { 
		if($iwer_id !== null) {
			foreach ($iwer_id as $id) {
				if(is_int($id) === false) {
					throw new ErrorException("Array \$iwer_id must contain only integers.");
				}
			}
		}
		
		$this->update_id_known = $update_id_known;
		$this->iwee_id = $iwee_id;
		$this->iwee_email = $iwee_email;
		$this->iwer_id = $iwer_id;
		$this->iw_id = $iw_id;
		
		// TODO sanitization?
	}
}

interface Database {
	/**
	 * @return string of the operator type when the password matches
	 * @return false when the password has no match
	 */
	public function operator_mapping(string $password) : string|false;
	/**
	 * @return true on success
	 * @return string on failure with the reason
	 */
	public function update_handle(Update $update, UpdateArguments $arguments) : true | string;
	/**
	 * @return int id
	 */
	public function update_happened_recent() : int;

	public function retrieve(string ...$from_table) : array;
}

interface DatabaseAdmin {
	public function create();
	public function drop();
	public function operator_entries() : array;
	public function operator_add(string $type, string $password, string $reminder) : bool;
	public function operator_remove(int|bool $id) : bool;
}

class UpdateHandleExpectedException extends Exception {}

class UpdateHandleUnexpectedException extends Exception {}

class Postgres implements Database, DatabaseAdmin {

	private array $conf;
	private ?PDO $pdo;
	
	public function __construct(array $configuration) {
		$this->conf = $configuration['dbms'];
	}

	private function connect(bool $with_database_selection, ?callable $on_success = null) {
		$dsn = "pgsql:host={$this->conf['host']}";

		if ($with_database_selection) {
			$dsn .= ";dbname={$this->conf['dbname']}";
		}

		$options = [
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES   => false,
		];

		try {
			$this->pdo = new PDO($dsn, $this->conf['user'], $this->conf['password'], $options);

			if( $on_success !== null ) {
				$result = $on_success();
			}

			$this->pdo = null;

			if ( isset($result) ) {
				return $result;
			}

		} catch (PDOException $e) {
			throw new Exception("Connection failed: " . $e->getMessage());
		}
	}

	// ||
	// \/ methods of Database interface
	
	public function operator_mapping(string $password) : string|false {
		return $this->connect(true, function() use ($password) {
			$result = $this->pdo->query("SELECT type, pass FROM operator;");

			if ($result === false || empty($entries = $result->fetchAll())) {
				return false;
			}

			foreach ($entries as $entry) {
				if (password_verify($password, $entry['pass'])) {
					return $entry['type'];
				}
			}

			return false;
		});
	}

	public function update_handle(Update $update, UpdateArguments $arguments) : true | string {
		return $this->connect(true,
			function() use ($update, $arguments) : true | string {
				$result = true;

				try {
					if($this->pdo->beginTransaction() === false) {
						throw new UpdateHandleUnexpectedException("unable to begin transaction");
					}
					
					// TODO (haha) no need to do it for all updates, make it better
					if($this->pdo->query('LOCK TABLE interview IN EXCLUSIVE MODE;') === false) {
						throw new UpdateHandleUnexpectedException("unable to acquire lock for shared data");
					}
					
					$urid = $this->pdo->query("SELECT * FROM update_recent_id;");

					if($urid === false) {
						throw new UpdateHandleUnexpectedException("unable to retrieve recent update");
					}
					
					if($urid->fetch()['recent'] !== $arguments->update_id_known) {
						throw new UpdateHandleExpectedException("some updates happened before your submission, they should have been send to you by now or soon");
					}

					$updated = false;

					switch ($update) { # TODO can be build better with classes probably?
						case Update::SECRETARY_ADD_INTERVIEWEE:
							if ($arguments->iwee_email === null) {
								break;
							}

							// ===

							$statement = $this->pdo->query("INSERT
								INTO interviewee (email, active, available)
								VALUES ('{$arguments->iwee_email}', true, true)
								ON CONFLICT (email) DO NOTHING;
							");
							
							// ===
							
							$updated = $statement !== false;
							break;

						case Update::SECRETARY_DELETE_INTERVIEWEE:
							if ($arguments->iwee_id === null) {
								break;
							}

							// ===
							
							# TODO if unavailable dont delete

							$statement =  $this->pdo->query("DELETE
								FROM interviewee
								WHERE id = {$arguments->iwee_id};
							");
							
							// TODO delete interviews related to iwee_id and handle related interviers availablility?

							// ===

							$updated = $statement !== false;
							break;

						default: break;
					}

					if($updated === true) {
						# $this->update_handle(Update::SYSTEM_ENQUEUED_TO_CALLING, new UpdateArguments());
						# TODO do it here dont call update_handle again for simplicity

						if($this->pdo->query("INSERT INTO updates (happened) VALUES (NOW());") === false) {
							throw new UpdateHandleUnexpectedException("unable to insert update timestamp");
						}
						# TODO recreate it with triggers in the database when one of the tables is affected?
					}
					# TODO if not updated throw exception (thus roll backing)
					# parameters should be validated with classes earlier (check TODO on switch above)
					# so the only reason to fail during updating is for something unexpected?

					if($this->pdo->commit() === false) {
						throw new UpdateHandleUnexpectedException("unable to commit transaction");
					}
				}
				catch (UpdateHandleUnexpectedException $e) {
					$result = "(should not happen) " . $e->getMessage();
				}
				catch (UpdateHandleExpectedException $e) {
					$result = $e->getMessage();
				}
				catch (Throwable $th) {
					$result = "(did not know that will happen) " . $th->getMessage();
				}
				finally {
					if($this->pdo->inTransaction()) {
						$this->pdo->rollBack();
					}
				}

				return $result;
			}
		);
	}

	public function update_happened_recent() : int {
		return $this->connect(true, function() {
			$statement = $this->pdo->query("SELECT * FROM update_recent_id;");
			return $statement === false ? 0 : $statement->fetch()['recent'];
		});
	}

	public function retrieve(string ...$from_table) : array {
		return $this->connect(true, function () use ($from_table) {
			$retrieved = [];

			$this->pdo->beginTransaction();
			
			try {
				foreach($from_table as $table) {
					$retrieved[$table] = $this->pdo->query("SELECT * FROM {$table};")->fetchAll(); 
				}

				$statement = $this->pdo->query("SELECT * FROM update_recent_id;");
				$retrieved['update'] = $statement === false ? 0 : $statement->fetch()['recent'];
			}
			catch (Throwable $th) {
				$this->pdo->rollBack();
			}

			$this->pdo->commit();

			return $retrieved;
		});
	}

	// ||
	// \/ methods of DatabaseAdmin interface

	public function create() {
		$result = $this->connect(false, function() {
			$exists = $this->pdo->query("SELECT 1 FROM pg_database WHERE datname = '{$this->conf['dbname']}'")->fetchColumn();
		
			if ($exists !== false) {
				return false;
			}
		
			return $this->pdo->query("CREATE DATABASE {$this->conf['dbname']};") !== false;
		});

		if ($result === false) {
			echo "Database already exists.\n";
			return;
		}

		echo "Database created.\n";

		$result = $this->connect(true, function() {
			$tables = [ // creation queries

				"CREATE TABLE IF NOT EXISTS operator (
					id SERIAL PRIMARY KEY,
					
					pass VARCHAR(255) NOT NULL,
					type VARCHAR(255) NOT NULL,
					reminder VARCHAR(255) NOT NULL
				);",

				# ---

				"CREATE TABLE IF NOT EXISTS interviewee (
					id SERIAL PRIMARY KEY,

					email VARCHAR(255) UNIQUE NOT NULL,

					active BOOLEAN NOT NULL,
					available BOOLEAN NOT NULL
				);",

				"CREATE TABLE IF NOT EXISTS interviewer /* or company */ (
					id SERIAL PRIMARY KEY,

					name VARCHAR(255) NOT NULL
					-- logo_resource_url VARCHAR(255) NOT NULL,
					-- table_number VARCHAR(255) NOT NULL,

					-- active BOOLEAN NOT NULL,
					-- available BOOLEAN NOT NULL
				);",

				"CREATE TABLE IF NOT EXISTS interview (
					id SERIAL PRIMARY KEY,

					id_interviewer INTEGER NOT NULL REFERENCES interviewer(id),
					id_interviewee INTEGER NOT NULL REFERENCES interviewee(id),

					state VARCHAR(255) NOT NULL,
					state_timestamp TIMESTAMP NOT NULL
				);",

				# ---

				"CREATE TABLE IF NOT EXISTS updates (
					id SERIAL,
					happened TIMESTAMP NOT NULL -- in UTC
				);",

				# ---

				"CREATE VIEW update_recent_id AS SELECT COALESCE(MAX(id),0) AS recent FROM updates;",

				// // keep it in case of need can be removed in later comments
				// "CREATE VIEW update_recent_ms AS
				// 	SELECT COALESCE(
				// 		FLOOR( MAX(EXTRACT(EPOCH FROM happened)) * 1000 ),
				// 		0
				// 	) AS recent
				// 	FROM update_timestamps
				// ;",

			];

			foreach($tables as $t) {
				if($this->pdo->query($t) === false)
					return false;
			}

			return true;
		});

		if ($result === false) {
			echo "Tables creation failed. Dropping database.\n";
			$this->drop();
			return;
		}

		echo "Tables created.\n";
	}

	public function drop() {
		$result = $this->connect(false, function() {
			if ($this->pdo->query("DROP DATABASE IF EXISTS {$this->conf['dbname']};") === false) {
				return false;
			}

			return true;
		});

		if ($result === false) {
			echo "Database did not drop.\n";
			return;
		}

		echo "Database dropped.\n";
	}

	public function operator_entries() : array {
		return $this->connect(true, function() {
			$query = "SELECT * FROM operator;";
			$result = $this->pdo->query($query);
			return $result === false ? [] : $result->fetchAll();
		});
	}

	public function operator_add(string $type, string $password, string $reminder) : bool {
		$entries = $this->operator_entries();
		
		return $this->connect(true, function() use ($entries, $type, $password, $reminder) {

			foreach ($entries as $entry) {
				if (password_verify($password, $entry['pass'])) {
					return false;
				}
			}

			$pass = password_hash($password, PASSWORD_BCRYPT);

			return $this->pdo->query("INSERT INTO operator (pass, type, reminder) VALUES ('{$pass}', '{$type}', '{$reminder}');") !== false;	
		});
	}

	public function operator_remove(bool|int $id_or_all) : bool {
		if ($id_or_all === false) {
			return false;
		}

		return $this->connect(true, function() use ($id_or_all) {

			if ($id_or_all === true) {
				$query = "TRUNCATE operator RESTART IDENTITY;";
			}
			else {
				$query = "DELETE FROM operator WHERE id = {$id_or_all};";
			}

			return $this->pdo->query($query) !== false;
		});
	}
}

function database() : Database {
	return new Postgres(require_once __DIR__ . '/config.php');
}

function database_admin() : DatabaseAdmin {
	return new Postgres(require_once __DIR__ . '/config.php');
}
