import 'package:flutter/material.dart';

/// جزئیات هنگام اولین باز شدن ساخته می‌شوند و پس از بستن حفظ می‌شوند.
class AdminDetailGroup extends StatefulWidget {
  const AdminDetailGroup(
      {super.key,
      required this.title,
      required this.subtitle,
      required this.icon,
      required this.builder});
  final String title, subtitle;
  final IconData icon;
  final WidgetBuilder builder;
  @override
  State<AdminDetailGroup> createState() => _AdminDetailGroupState();
}

class _AdminDetailGroupState extends State<AdminDetailGroup>
    with AutomaticKeepAliveClientMixin {
  bool _opened = false;
  @override
  bool get wantKeepAlive => true;
  @override
  Widget build(BuildContext context) {
    super.build(context);
    return Card(
      clipBehavior: Clip.antiAlias,
      child: ExpansionTile(
        maintainState: true,
        leading:
            Icon(widget.icon, color: Theme.of(context).colorScheme.primary),
        title:
            Text(widget.title, style: Theme.of(context).textTheme.titleSmall),
        subtitle: Text(widget.subtitle),
        onExpansionChanged: (open) {
          if (open && !_opened) setState(() => _opened = true);
        },
        children: [
          if (_opened)
            Padding(
                padding: const EdgeInsets.fromLTRB(12, 4, 12, 16),
                child: widget.builder(context))
        ],
      ),
    );
  }
}
