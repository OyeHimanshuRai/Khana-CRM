import 'package:flutter/material.dart';

import '../api.dart';
import '../main.dart';
import 'order_screen.dart';

// One table: what the party has ordered and what they owe.
class TableScreen extends StatefulWidget {
  const TableScreen({super.key, required this.tableId});

  final int tableId;

  @override
  State<TableScreen> createState() => _TableScreenState();
}

class _TableScreenState extends State<TableScreen> {
  Map<String, dynamic>? _table;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final data = await Api.instance.get('/tables/${widget.tableId}');
      setState(() => _table = data);
    } catch (e) {
      if (mounted) showError(context, e);
    }
  }

  Future<void> _order() async {
    final placed = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => OrderScreen(tableId: widget.tableId, tableName: '${_table?['name'] ?? ''}')),
    );
    if (placed == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    final table = _table;
    final session = table?['session'] as Map<String, dynamic>?;
    final lines = List<Map<String, dynamic>>.from(session?['lines'] as List? ?? []);

    return Scaffold(
      appBar: AppBar(title: Text(table == null ? 'Table' : 'Table ${table['name']}')),
      floatingActionButton: Api.instance.can('orders:write') && table != null
          ? FloatingActionButton.extended(onPressed: _order, icon: const Icon(Icons.add), label: const Text('Order'))
          : null,
      body: table == null
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.only(bottom: 96),
                children: [
                  ListTile(
                    title: Text('${table['status_label'] ?? table['status']}'),
                    subtitle: Text('${table['area'] ?? ''} · ${table['seats']} seats'),
                  ),
                  if (session == null)
                    const Padding(padding: EdgeInsets.all(32), child: Center(child: Text('Nobody is seated here.')))
                  else ...[
                    ListTile(
                      title: Text('${session['party'] ?? 'Guests'}'),
                      subtitle: Text('${session['covers'] ?? '-'} covers · ${session['in_kitchen'] ?? 0} in kitchen'),
                    ),
                    const Divider(),
                    for (final line in lines)
                      ListTile(
                        dense: true,
                        title: Text('${line['quantity']} × ${line['name']}${line['size'] != null ? ' (${line['size']})' : ''}'),
                        subtitle: Text([
                          ...List<String>.from((line['add_ons'] as List? ?? []).map((a) => '$a')),
                          if (line['note'] != null) '${line['note']}',
                          '${line['kitchen_status'] ?? ''}',
                        ].where((s) => s.isNotEmpty).join(' · ')),
                        trailing: Text(money(toDouble(line['unit_price']) * toDouble(line['quantity']))),
                      ),
                    const Divider(),
                    _total('Not billed yet', session['unbilled']),
                    _total('Billed', session['billed']),
                    _total('Paid', session['paid']),
                    _total('Due', session['due'], bold: true),
                  ],
                ],
              ),
            ),
    );
  }

  Widget _total(String label, Object? amount, {bool bold = false}) {
    final style = bold ? const TextStyle(fontWeight: FontWeight.bold) : null;
    return ListTile(dense: true, title: Text(label, style: style), trailing: Text(money(amount), style: style));
  }
}
