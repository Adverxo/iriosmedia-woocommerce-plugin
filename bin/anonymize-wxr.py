#!/usr/bin/env python3
"""
Anonymises a WordPress/WooCommerce WXR export before importing it into the Docker test store.

Usage: python3 -I bin/anonymize-wxr.py <input.xml> <output.xml>

- Orders and refunds: names, company, addresses, email, phone, IP, user agent, customer note, visit origin and payment
  details are replaced with stable fake data (the same real customer → the same "Cliente N"). Country, state, city and
  postcode, status, dates, amounts and payment method are kept. Order notes (comments) are removed.
- Coupons: every "_used_by" (emails / ids of who used them) and the emails in the description are emptied.
- Contact Form 7 forms: recipients become admin@example.com (the local store does not write to anyone real).
- Authors: replaced with "admin".

The input file is not modified.
"""
import re
import sys
import xml.etree.ElementTree as ET

WP = 'http://wordpress.org/export/1.2/'
NS = {
    'wp': WP,
    'excerpt': 'http://wordpress.org/export/1.2/excerpt/',
    'content': 'http://purl.org/rss/1.0/modules/content/',
    'dc': 'http://purl.org/dc/elements/1.1/',
}
for prefix, uri in [('excerpt', NS['excerpt']), ('content', NS['content']), ('wfw', 'http://wellformedweb.org/CommentAPI/'),
                    ('dc', NS['dc']), ('wp', WP)]:
    ET.register_namespace(prefix, uri)

ORDER_TYPES = {'shop_order', 'shop_order_refund'}
EMPTY = {
    '_billing_company', '_shipping_company', '_billing_address_2', '_shipping_address_2',
    '_customer_user_agent', '_transaction_id', '_authorisation_code_redsys', '_order_fuc_redsys',
    '_payment_order_number_redsys', '_card_country_redsys', '_card_type_redsys',
    '_wc_order_attribution_user_agent', '_wc_order_attribution_referrer', '_wc_order_attribution_session_entry',
    '_wc_order_attribution_utm_content', '_wc_order_attribution_utm_medium', '_wc_order_attribution_utm_source',
    '_billing_address_index', '_shipping_address_index', '_refund_reason',
}


def meta_map(item):
    out = {}
    for meta in item.findall('wp:postmeta', NS):
        out[meta.find('wp:meta_key', NS).text] = meta
    return out


def set_meta(meta, value):
    meta.find('wp:meta_value', NS).text = value


EMAIL = re.compile(r'[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}')


def replace_emails(el, replacement):
    if el is not None and el.text:
        el.text = EMAIL.sub(replacement, el.text)


def main(src, dst):
    tree = ET.parse(src)
    channel = tree.getroot().find('channel')
    customers = {}

    for author in channel.findall('wp:author', NS):
        for tag, value in [('author_login', 'admin'), ('author_email', 'admin@example.com'),
                           ('author_display_name', 'admin'), ('author_first_name', ''), ('author_last_name', '')]:
            el = author.find('wp:' + tag, NS)
            if el is not None:
                el.text = value

    orders = coupons = 0
    for item in channel.findall('item'):
        creator = item.find('dc:creator', NS)
        if creator is not None:
            creator.text = 'admin'
        post_type = item.find('wp:post_type', NS).text

        if post_type == 'shop_coupon':
            for meta in item.findall('wp:postmeta', NS):
                if meta.find('wp:meta_key', NS).text == '_used_by':
                    set_meta(meta, '')
            replace_emails(item.find('content:encoded', NS), 'cliente@example.test')
            replace_emails(item.find('excerpt:encoded', NS), 'cliente@example.test')
            coupons += 1
            continue
        if post_type == 'wpcf7_contact_form':
            for meta in item.findall('wp:postmeta', NS):
                replace_emails(meta.find('wp:meta_value', NS), 'admin@example.com')
            replace_emails(item.find('content:encoded', NS), 'admin@example.com')
            continue
        if post_type not in ORDER_TYPES:
            continue

        orders += 1
        metas = meta_map(item)
        email_meta = metas.get('_billing_email')
        key = (email_meta.find('wp:meta_value', NS).text or '').strip().lower() if email_meta is not None else ''
        key = key or 'order:' + item.find('wp:post_id', NS).text
        n = customers.setdefault(key, len(customers) + 1)

        fake = {
            '_billing_first_name': 'Cliente', '_shipping_first_name': 'Cliente',
            '_billing_last_name': str(n), '_shipping_last_name': str(n),
            '_billing_address_1': f'Calle Ejemplo {n}', '_shipping_address_1': f'Calle Ejemplo {n}',
            '_billing_email': f'cliente{n}@example.test',
            '_billing_phone': f'6{n:08d}', '_shipping_phone': f'6{n:08d}',
            '_customer_ip_address': '127.0.0.1',
        }
        for meta_key, meta in metas.items():
            if meta_key in fake:
                set_meta(meta, fake[meta_key])
            elif meta_key in EMPTY or 'email' in meta_key or 'phone' in meta_key:
                set_meta(meta, '')

        excerpt = item.find('excerpt:encoded', NS)
        if excerpt is not None:
            excerpt.text = ''
        for comment in item.findall('wp:comment', NS):
            item.remove(comment)

    tree.write(dst, encoding='UTF-8', xml_declaration=True)
    print(f'{orders} orders/refunds anonymised ({len(customers)} customers), {coupons} coupons → {dst}')


if __name__ == '__main__':
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    main(sys.argv[1], sys.argv[2])
