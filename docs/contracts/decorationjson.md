# DecorationJSON contract

`custom.decorationjson` is the basis of the CMAU PDP pricing calculator. Its keys, value formats and ordering are a fixed contract with the theme: change none of them without changing the theme. The machine-checkable version is [decorationjson.schema.json](decorationjson.schema.json).

## Shape

```json
{
  "BrandFlag": "Price includes branding",
  "UndecoratedPriceSetup": "0",
  "UndecoratedPriceString": "1:0.67,500:0.66,1000:0.64,2500:0.63,5000:0.61,10000:0.6",
  "Decorations": [
    {
      "DecID": "2568-padprintpercolourpositionpenpositionsde45mmx7mmpenpositionsde32mmx8mm",
      "DecName": "Pad Print Per Colour/Position (Pen - Positions D & E - 45mm x 7mm | Pen - Positions D & E - 32mm x 8mm)",
      "CombinedSetup": "60",
      "CombinedPrcString": "1:0.18,500:0.18,1000:0.18,2500:0.18,5000:0.18,10000:0.18"
    }
  ]
}
```

## Rules (from TOD queries `PDSProducts - create DecorationJSON part 1-3` and `DecJSON - ConcatQuery`)

| Key | Source |
| --- | --- |
| `BrandFlag` | `"Price includes branding"` if the product has a type-10 decoration available (WebDecAvailableFlag), otherwise `"Price does not include branding"` |
| `UndecoratedPriceSetup` | WebUnDecPriceSetup, as a string |
| `UndecoratedPriceString` | WebUnDecPriceString (`qty:unit` breaks, comma separated), as a string |
| `Decorations` | One entry per CostPriceSummary row for the product's live decoration APPAID (WebDecLiveAPPAID) where TypeCode = 10 and the price string is not null |
| `DecID` | PDSCP_TextPDID: `APPAID-` + the lower-case alphanumeric description for Promodata lines. External (EXT) decorations use their own TOD IDs, which can include brackets and hyphens, for example `10002000-embroidery(topocketsize)` |
| `DecName` | PDSCP_OverwriteDec, else PDSCP_Desc, with commas removed |
| `CombinedSetup` | PDSCP_SetupPrice, as a string |
| `CombinedPrcString` | PDSCP_QtyPriceString, as a string |

Decorations are ordered by DecJSONSort (1 = the default decoration, 2 = others). Ties are broken by the unit price at 50 units plus the setup price.

Every value is a string, including the numbers. Decimal places are dropped for whole numbers (`"60"`, not `"60.00"`).

## Validation against live data

The schema was checked against all 4,991 values from the 19 Jul 2026 reload. 4,982 pass. The 9 that fail are not valid JSON at all (see below).

One product (handle 74109) has a decoration with a negative unit price (`-2.55`). The schema allows it, but it is worth checking that this is intentional.

## Known defect in TOD

TOD builds the JSON by string concatenation and only strips commas from names. Names that contain line breaks therefore produce invalid JSON. Nine products were affected in the 19 Jul 2026 reload: handles 2703, 2834, 2836, 2837, 2838, 2839, 2845, 2846 and 3100.

The SaaS must:

- build the value with a real JSON serialiser, so every string is escaped;
- normalise whitespace in `DecName` (collapse line breaks and repeated spaces) and keep removing commas, so output matches what the theme already expects;
- validate against the schema before publishing, and block publishing of any product that fails.
