import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import 'dashboard_screen.dart';
import 'login_screen.dart';
import 'tables_screen.dart';

// Bottom tabs; a tab shows only when the person's role allows it.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _tab = 0;
  int _refresh = 0;

  Future<void> _chooseOutlet() async {
    try {
      final data = await Api.instance.get('/outlets');
      final outlets = List<Map<String, dynamic>>.from(data['data'] as List);
      if (!mounted) return;
      final chosen = await showModalBottomSheet<Map<String, dynamic>>(
        context: context,
        builder: (_) => SafeArea(
          child: ListView(
            shrinkWrap: true,
            children: [
              for (final outlet in outlets)
                ListTile(
                  leading: Icon(outlet['current'] == true ? Icons.radio_button_checked : Icons.radio_button_off),
                  title: Text('${outlet['name']}'),
                  subtitle: Text('${outlet['code'] ?? ''}'),
                  onTap: () => Navigator.pop(context, outlet),
                ),
            ],
          ),
        ),
      );
      if (chosen == null || chosen['current'] == true) return;
      final switched = await Api.instance.put('/outlet', {'shop_id': chosen['id']});
      await Api.instance.setShopName('${switched['shop']['name']}');
      setState(() => _refresh++);
    } catch (e) {
      if (mounted) showError(context, e);
    }
  }

  Future<void> _logout() async {
    await Api.instance.logout();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const LoginScreen()));
  }

  @override
  Widget build(BuildContext context) {
    final api = Api.instance;
    final tabs = <(String, IconData, Widget)>[
      if (api.can('tables:read')) ('Tables', Icons.table_restaurant, TablesScreen(key: ValueKey('t$_refresh'))),
      if (api.can('dashboard:read')) ('Dashboard', Icons.insights, DashboardScreen(key: ValueKey('d$_refresh'))),
    ];
    final tab = tabs.isEmpty ? null : tabs[_tab.clamp(0, tabs.length - 1)];

    return Scaffold(
      appBar: AppBar(
        title: InkWell(
          onTap: _chooseOutlet,
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Flexible(child: Text(api.shopName.isEmpty ? 'Choose outlet' : api.shopName, overflow: TextOverflow.ellipsis)),
            const Icon(Icons.arrow_drop_down),
          ]),
        ),
        actions: [
          PopupMenuButton<String>(
            onSelected: (_) => _logout(),
            itemBuilder: (_) => [
              PopupMenuItem(enabled: false, child: Text(api.userName)),
              const PopupMenuItem(value: 'logout', child: Text('Sign out')),
            ],
          ),
        ],
      ),
      body: tab?.$3 ?? const Center(child: Text('Your role has nothing to show in this app yet.')),
      bottomNavigationBar: tabs.length < 2
          ? null
          : NavigationBar(
              selectedIndex: _tab.clamp(0, tabs.length - 1),
              onDestinationSelected: (i) => setState(() => _tab = i),
              destinations: [for (final t in tabs) NavigationDestination(icon: Icon(t.$2), label: t.$1)],
            ),
    );
  }
}
