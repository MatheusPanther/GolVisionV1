<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Repositories\UserRepository;
use Throwable;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('/dashboard');
        }

        $this->render('auth/login', [
            'pageTitle' => 'Entrar | GoalVision AI',
            'showSidebar' => false,
        ]);
    }

    public function login(): void
    {
        $this->requirePost();
        $this->verifyCsrf();

        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        remember_old(['email' => $email]);

        if ($email === '' || $password === '') {
            flash('error', 'Preencha email e senha para entrar.');
            $this->redirect('/login');
        }

        try {
            sync_configured_admin_accounts();
            $attempted = Auth::attempt($email, $password);
        } catch (Throwable $exception) {
            app_log('error', 'Falha no login ao consultar MySQL.', [
                'email' => strtolower($email),
                'exception' => $exception->getMessage(),
            ]);
            flash('error', friendly_database_error($exception, 'Nao foi possivel consultar o banco MySQL agora. Revise a conexao e tente novamente.'));
            $this->redirect('/login');
        }

        if (!$attempted) {
            flash('error', 'Credenciais invalidas. Revise os dados e tente novamente.');
            $this->redirect('/login');
        }

        clear_old(['email']);
        flash('success', 'Login realizado com sucesso.');
        $this->redirect('/dashboard');
    }

    public function register(): void
    {
        $this->requirePost();
        $this->verifyCsrf();

        $data = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'password' => (string) ($_POST['password'] ?? ''),
            'confirm_password' => (string) ($_POST['confirm_password'] ?? ''),
            'accepted_terms' => isset($_POST['accepted_terms']),
            'is_18_confirmed' => isset($_POST['is_18_confirmed']),
            'plan' => 'free',
        ];

        remember_old([
            'register_name' => $data['name'],
            'register_email' => $data['email'],
        ]);

        if ($data['name'] === '' || $data['email'] === '' || $data['password'] === '') {
            flash('error', 'Preencha nome, email e senha para criar sua conta.');
            $this->redirect('/login');
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Informe um email valido.');
            $this->redirect('/login');
        }

        if ($data['password'] !== $data['confirm_password']) {
            flash('error', 'As senhas nao conferem.');
            $this->redirect('/login');
        }

        if (strlen($data['password']) < 8) {
            flash('error', 'Use uma senha com pelo menos 8 caracteres.');
            $this->redirect('/login');
        }

        if (!$data['accepted_terms'] || !$data['is_18_confirmed']) {
            flash('error', 'Voce precisa aceitar os termos e confirmar que tem 18+.');
            $this->redirect('/login');
        }

        try {
            $repository = new UserRepository();

            if ($repository->findByEmail($data['email']) !== null) {
                flash('error', 'Ja existe uma conta com esse email.');
                $this->redirect('/login');
            }

            $user = $repository->create($data);
            Auth::login($user);
            clear_old(['register_name', 'register_email']);

            flash('success', 'Conta criada com sucesso.');
            $this->redirect('/dashboard');
        } catch (Throwable $exception) {
            app_log('error', 'Falha ao criar conta.', [
                'email' => strtolower((string) $data['email']),
                'exception' => $exception->getMessage(),
            ]);
            flash('error', friendly_database_error($exception, 'Nao foi possivel criar a conta agora. Verifique o banco MySQL e tente novamente.'));
            $this->redirect('/login');
        }
    }

    public function logout(): void
    {
        $this->requirePost();
        $this->verifyCsrf();

        Auth::logout();
        flash('success', 'Sessao encerrada.');
        $this->redirect('/login');
    }
}
