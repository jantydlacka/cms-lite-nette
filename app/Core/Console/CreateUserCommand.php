<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Model\User\CreateUser;
use App\Model\User\InvalidUserException;
use App\Model\User\Role;
use App\Model\User\UserService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'user:create', description: 'Creates a user of the administration')]
final class CreateUserCommand extends Command
{
	private const MESSAGES = [
		InvalidUserException::EMAIL_FORMAT => 'E-mail is not valid.',
		InvalidUserException::EMAIL_TAKEN => 'A user with this e-mail already exists.',
		InvalidUserException::NAME_EMPTY => 'Name must not be empty.',
		InvalidUserException::NAME_LONG => 'Name is too long.',
		InvalidUserException::PASSWORD_SHORT => 'Password must be at least 12 characters.',
		InvalidUserException::PASSWORD_LONG => 'Password must be at most 72 bytes.',
		InvalidUserException::PASSWORD_WHITESPACE => 'Password must not start or end with whitespace.',
	];


	public function __construct(private readonly UserService $users)
	{
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addArgument('email', InputArgument::REQUIRED, 'E-mail (the login)')
			->addArgument('name', InputArgument::REQUIRED, 'Display name')
			->addOption('role', null, InputOption::VALUE_REQUIRED, 'admin or editor', Role::Admin->value)
			->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from the first line of standard input');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$roleName = $input->getOption('role');
		assert(is_string($roleName)); // VALUE_REQUIRED with a default
		$role = Role::tryFrom($roleName);
		if ($role === null) {
			return $this->fail($output, sprintf("Unknown role '%s'.", $roleName));
		}

		$fromStdin = $input->getOption('password-stdin') === true;
		$password = $fromStdin ? $this->readStdin($input) : $this->ask($input, $output);
		if ($password === null) {
			return $this->fail($output, $fromStdin
				? 'No password given on standard input.'
				: 'No password given; use --password-stdin when not running interactively.');
		}
		if ($password === false) {
			return $this->fail($output, 'Passwords do not match.');
		}

		$email = $input->getArgument('email');
		$name = $input->getArgument('name');
		assert(is_string($email) && is_string($name));
		try {
			$user = $this->users->create(new CreateUser($email, $name, $password, $role));
		} catch (InvalidUserException $e) {
			return $this->fail($output, self::MESSAGES[$e->reason]);
		}

		$output->writeln(sprintf('Created user #%d %s (%s).', $user->id, $user->email, $user->role->value));

		return Command::SUCCESS;
	}


	private function readStdin(InputInterface $input): ?string
	{
		$stream = $input instanceof StreamableInputInterface ? ($input->getStream() ?? STDIN) : STDIN;
		$line = fgets($stream);

		// Only the line ending goes: spaces are the service's business.
		return $line === false ? null : preg_replace('~\r?\n$~', '', $line);
	}


	/** Null when no input came, false when the two entries differ. */
	private function ask(InputInterface $input, OutputInterface $output): string|false|null
	{
		$helper = new QuestionHelper();
		$first = (new Question('Password: '))->setHidden(true);
		$second = (new Question('Repeat password: '))->setHidden(true);
		$password = $helper->ask($input, $output, $first);
		$repeat = $helper->ask($input, $output, $second);

		if (!is_string($password) || !is_string($repeat)) {
			return null;
		}

		return $password === $repeat ? $password : false;
	}


	private function fail(OutputInterface $output, string $message): int
	{
		$output->writeln($message);

		return Command::FAILURE;
	}
}
