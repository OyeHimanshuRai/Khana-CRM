import 'package:flutter/material.dart';

import 'api.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const PartnerApp());
}

final navigatorKey = GlobalKey<NavigatorState>();

// Shows an error, and sends the person back to sign-in when the token has ended.
void showError(BuildContext context, Object error) {
  if (error is ApiException && error.status == 401) {
    Api.instance.logout();
    navigatorKey.currentState?.pushAndRemoveUntil(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
      (_) => false,
    );
  }
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.toString())));
}

class PartnerApp extends StatelessWidget {
  const PartnerApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Restro One Partner',
      navigatorKey: navigatorKey,
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: Colors.deepOrange), useMaterial3: true),
      home: const StartScreen(),
    );
  }
}

class StartScreen extends StatefulWidget {
  const StartScreen({super.key});

  @override
  State<StartScreen> createState() => _StartScreenState();
}

class _StartScreenState extends State<StartScreen> {
  @override
  void initState() {
    super.initState();
    _open();
  }

  Future<void> _open() async {
    final signedIn = await Api.instance.restore();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => signedIn ? const HomeScreen() : const LoginScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(body: Center(child: CircularProgressIndicator()));
  }
}
