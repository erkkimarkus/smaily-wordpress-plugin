import { useRef, useState } from 'react';

import { type RssFeedBootData } from '../../state/types';
import { buildRssFeedUrl } from '../../utils/rss-feed-url';
import { Button, Card, Input, Label, NumberInput, Select, type SelectOption } from '../primitives';

export interface RssFeedSectionProps {
  rss: RssFeedBootData;
}

/**
 * Product RSS feed URL builder — renders under the integration cards on
 * Step 5 / the Integrations tab.
 *
 * Purely client-side: the feed endpoint reads every parameter from the
 * URL's query string, so the generated URL IS the configuration and
 * nothing here persists (which is also why this section lives on the
 * save-footer-less Integrations tab without special-casing). Field
 * prefill comes from the merchant's previously-saved legacy RSS options
 * via the boot payload, so a migrated store sees its old setup.
 *
 * Field set, ranges and defaults mirror the legacy RSS settings tab
 * (admin/smaily-admin-settings.class.php register_rss_tab_settings).
 */
export function RssFeedSection({ rss }: RssFeedSectionProps): React.JSX.Element {
  const [category, setCategory] = useState(rss.defaults.category);
  const [limit, setLimit] = useState(String(rss.defaults.limit));
  const [sortBy, setSortBy] = useState(rss.defaults.sortBy);
  const [order, setOrder] = useState(rss.defaults.order);
  const [taxRate, setTaxRate] = useState(String(rss.defaults.taxRate));
  const [copied, setCopied] = useState(false);
  const urlInputRef = useRef<HTMLInputElement>(null);

  const feedUrl = buildRssFeedUrl(rss.baseUrl, { category, limit, sortBy, order, taxRate });

  const handleCopy = (): void => {
    // navigator.clipboard needs a secure context — a plain-http wp-admin
    // (not unheard of on pilot/staging stores) doesn't have one. Fall
    // back to selecting the URL so a manual Ctrl+C still works.
    if (typeof navigator !== 'undefined' && navigator.clipboard !== undefined) {
      navigator.clipboard.writeText(feedUrl).then(
        () => {
          setCopied(true);
          setTimeout(() => setCopied(false), 2000);
        },
        () => urlInputRef.current?.select(),
      );
    } else {
      urlInputRef.current?.select();
    }
  };

  const categoryOptions: SelectOption[] = [
    { value: '', label: 'All' },
    ...rss.categories.map((c) => ({ value: c.slug, label: c.name })),
  ];

  return (
    <Card
      title="Product RSS feed"
      description="Load store products straight into your newsletters: paste this feed URL into a Smaily template's RSS block and the products appear in the email. The settings below only shape the URL — nothing is saved here."
    >
      <div className="grid gap-4 md:grid-cols-5">
        <div className="space-y-1.5">
          <Label htmlFor="smaily-rss-category">Product category</Label>
          <Select
            id="smaily-rss-category"
            options={categoryOptions}
            value={category}
            onChange={(e) => setCategory(e.target.value)}
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="smaily-rss-limit">Limit</Label>
          <NumberInput
            id="smaily-rss-limit"
            min={1}
            max={250}
            value={limit}
            onChange={(e) => setLimit(e.target.value)}
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="smaily-rss-sort-field">Sort by</Label>
          <Select
            id="smaily-rss-sort-field"
            options={[
              { value: 'modified', label: 'Modified At' },
              { value: 'date', label: 'Created At' },
              { value: 'id', label: 'ID' },
              { value: 'name', label: 'Name' },
              { value: 'type', label: 'Type' },
            ]}
            value={sortBy}
            onChange={(e) => setSortBy(e.target.value)}
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="smaily-rss-sort-order">Order</Label>
          <Select
            id="smaily-rss-sort-order"
            options={[
              { value: 'ASC', label: 'Ascending' },
              { value: 'DESC', label: 'Descending' },
            ]}
            value={order}
            onChange={(e) => setOrder(e.target.value)}
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="smaily-rss-tax-rate">Tax rate</Label>
          <NumberInput
            id="smaily-rss-tax-rate"
            min={0}
            step={0.01}
            unit="%"
            value={taxRate}
            onChange={(e) => setTaxRate(e.target.value)}
          />
        </div>
      </div>

      <div className="mt-4 space-y-1.5">
        <Label htmlFor="smaily-rss-feed-url">Feed URL</Label>
        <div className="flex items-center gap-2">
          <Input
            id="smaily-rss-feed-url"
            ref={urlInputRef}
            readOnly
            value={feedUrl}
            onFocus={(e) => e.target.select()}
            className="font-mono text-sm"
          />
          <Button type="button" variant="secondary" onClick={handleCopy}>
            {copied ? 'Copied ✓' : 'Copy'}
          </Button>
        </div>
      </div>
    </Card>
  );
}
