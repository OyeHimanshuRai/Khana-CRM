import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import 'table_screen.dart';

class TablesScreen extends StatefulWidget {
  const TablesScreen({super.key});

  @override
  State<TablesScreen> createState() => _TablesScreenState();
}

class _TablesScreenState extends State<TablesScreen> {
  List<Map<String, dynamic>>? _tables;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await Api.instance.get('/tables');
      setState(() => _tables = List<Map<String, dynamic>>.from(data['data'] as List));
    } catch (e) {
      if (!mounted) return;
      setState(() => _tables ??= []);
      showError(context, e);
    }
  }

  Color _colour(String? status) => switch (status) {
        'available' => Colors.green,
        'occupied' => Colors.deepOrange,
        'reserved' => Colors.blue,
        'billing' => Colors.purple,
        _ => Colors.grey,
      };

  @override
  Widget build(BuildContext context) {
    final tables = _tables;
    if (tables == null) return const Center(child: CircularProgressIndicator());

    return RefreshIndicator(
      onRefresh: _load,
      child: tables.isEmpty
          ? ListView(children: const [Padding(padding: EdgeInsets.all(32), child: Center(child: Text('No tables at this outlet.')))])
          : GridView.builder(
              padding: const EdgeInsets.all(12),
              gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(maxCrossAxisExtent: 160, mainAxisSpacing: 12, crossAxisSpacing: 12),
              itemCount: tables.length,
              itemBuilder: (context, i) {
                final table = tables[i];
                final colour = _colour(table['status'] as String?);
                final next = table['next_booking'] as Map<String, dynamic>?;
                return InkWell(
                  borderRadius: BorderRadius.circular(12),
                  onTap: () async {
                    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TableScreen(tableId: table['id'] as int)));
                    _load();
                  },
                  child: Container(
                    decoration: BoxDecoration(
                      color: colour.withValues(alpha: 0.12),
                      border: Border.all(color: colour),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    padding: const EdgeInsets.all(8),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text('${table['name']}', style: Theme.of(context).textTheme.headlineSmall),
                        Text('${table['area'] ?? ''}', style: Theme.of(context).textTheme.bodySmall),
                        const SizedBox(height: 4),
                        Text('${table['status_label'] ?? table['status']}', style: TextStyle(color: colour, fontWeight: FontWeight.bold)),
                        Text('${table['seats']} seats', style: Theme.of(context).textTheme.bodySmall),
                        if (next != null) Text('Booked: ${next['name']}', maxLines: 1, overflow: TextOverflow.ellipsis, style: Theme.of(context).textTheme.bodySmall),
                      ],
                    ),
                  ),
                );
              },
            ),
    );
  }
}
