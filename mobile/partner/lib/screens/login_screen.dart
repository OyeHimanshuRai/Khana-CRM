import 'package:flutter/material.dart';
import 'package:flutter_appauth/flutter_appauth.dart';

import '../api.dart';
import 'home_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _showPassword = partnerClientId.isEmpty;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  // Accounts' own sign-in page with PKCE; its token is then swapped for a CRM token.
  Future<void> _signInWithAccounts() async {
    await _run(() async {
      final result = await const FlutterAppAuth().authorizeAndExchangeCode(
        AuthorizationTokenRequest(
          partnerClientId,
          redirectUrl,
          serviceConfiguration: const AuthorizationServiceConfiguration(
            authorizationEndpoint: '$baseUrl/accounts/oauth/authorize',
            tokenEndpoint: '$baseUrl/accounts/oauth/token',
          ),
          scopes: const [],
        ),
      );
      final accessToken = result.accessToken;
      if (accessToken == null) throw ApiException(0, 'Sign-in was cancelled.');
      await Api.instance.exchange(accessToken);
    });
  }

  Future<void> _signInWithPassword() async {
    await _run(() => Api.instance.login(_email.text.trim(), _password.text));
  }

  Future<void> _run(Future<void> Function() signIn) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await signIn();
      if (!mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const HomeScreen()));
    } on FlutterAppAuthUserCancelledException {
      setState(() => _busy = false);
    } catch (e) {
      setState(() {
        _busy = false;
        _error = e.toString();
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Icon(Icons.restaurant, size: 64, color: Theme.of(context).colorScheme.primary),
                const SizedBox(height: 12),
                Text('Restro One Partner', textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: 32),
                if (partnerClientId.isNotEmpty)
                  FilledButton(
                    onPressed: _busy ? null : _signInWithAccounts,
                    child: const Text('Sign in with Restro One'),
                  ),
                if (_showPassword) ...[
                  const SizedBox(height: 16),
                  TextField(
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(labelText: 'Email', border: OutlineInputBorder()),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _password,
                    obscureText: true,
                    decoration: const InputDecoration(labelText: 'Password', border: OutlineInputBorder()),
                    onSubmitted: (_) => _signInWithPassword(),
                  ),
                  const SizedBox(height: 12),
                  OutlinedButton(
                    onPressed: _busy ? null : _signInWithPassword,
                    child: const Text('Sign in with password'),
                  ),
                ] else
                  TextButton(
                    onPressed: () => setState(() => _showPassword = true),
                    child: const Text('Use email and password'),
                  ),
                if (_busy) const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator())),
                if (_error != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 16),
                    child: Text(_error!, textAlign: TextAlign.center, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
