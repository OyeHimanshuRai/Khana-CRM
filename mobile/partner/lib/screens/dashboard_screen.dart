import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';

// The owner's home figures; a tile the role may not see comes back null and is left out.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  Map<String, dynamic>? _data;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await Api.instance.get('/dashboard');
      setState(() => _data = data);
    } catch (e) {
      if (!mounted) return;
      setState(() => _data ??= {});
      showError(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = _data;
    if (data == null) return const Center(child: CircularProgressIndicator());

    final sales = data['sales'] as Map<String, dynamic>?;
    final alerts = List<Map<String, dynamic>>.from(data['alerts'] as List? ?? []);
    final soldOut = data['sold_out'] as List? ?? [];

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(12),
        children: [
          if (sales != null)
            Row(children: [
              _tile('Today', money(sales['today']), '${sales['today_count'] ?? 0} bills'),
              _tile('This month', money(sales['month']), null),
            ]),
          for (final name in ['profit', 'room', 'kitchen', 'dues', 'payments'])
            if (data[name] is Map) _figures(name, data[name] as Map<String, dynamic>),
          if (soldOut.isNotEmpty)
            Card(
              child: ListTile(
                leading: const Icon(Icons.no_food),
                title: const Text('Sold out'),
                subtitle: Text(soldOut.map((d) => d is Map ? '${d['name']}' : '$d').join(', ')),
              ),
            ),
          for (final alert in alerts)
            Card(
              color: alert['tone'] == 'danger' ? Theme.of(context).colorScheme.errorContainer : null,
              child: ListTile(leading: const Icon(Icons.notifications_active), title: Text('${alert['title']}')),
            ),
        ],
      ),
    );
  }

  Widget _tile(String label, String value, String? note) {
    return Expanded(
      child: Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: Theme.of(context).textTheme.labelLarge),
            Text(value, style: Theme.of(context).textTheme.headlineSmall),
            if (note != null) Text(note, style: Theme.of(context).textTheme.bodySmall),
          ]),
        ),
      ),
    );
  }

  // Shows only the plain numbers of a tile; nested lists stay on the web dashboard.
  Widget _figures(String name, Map<String, dynamic> figures) {
    final plain = figures.entries.where((e) => e.value is num).toList();
    if (plain.isEmpty) return const SizedBox.shrink();
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(_label(name), style: Theme.of(context).textTheme.titleMedium),
          for (final e in plain)
            Row(children: [
              Expanded(child: Text(_label(e.key))),
              Text(e.value is double ? (e.value as double).toStringAsFixed(2) : '${e.value}'),
            ]),
        ]),
      ),
    );
  }

  String _label(String key) {
    final words = key.replaceAll('_', ' ');
    return words[0].toUpperCase() + words.substring(1);
  }
}
