/** Shapes sent by Admin\SpendReportController / AdminSpendReportQuery. Money is display-only here. */
export type MoneyValue = { nanos: string; display: string };

export type SpendAmounts = {
    generations: number;
    charge: MoneyValue;
    provider_cost: MoneyValue;
    profit: MoneyValue;
    /** Profit as a share of what users paid, e.g. "20.00%"; null when nothing was charged. */
    margin: string | null;
};

export type SpendUserRow = SpendAmounts & {
    user_id: number;
    name: string;
    email: string | null;
    deleted: boolean;
};

export type SpendDay = SpendAmounts & {
    date: string;
    users: SpendUserRow[];
};

export type ProviderDayComparison =
    | { known: false }
    | {
          known: true;
          generations: number;
          cost: MoneyValue;
          matches: boolean;
          difference: {
              amount: MoneyValue;
              we_recorded: 'less' | 'more';
          } | null;
      };

export type PixelforgeComparison =
    | { available: false; message: string }
    | {
          available: true;
          matches: boolean;
          days: Record<string, ProviderDayComparison>;
      };
