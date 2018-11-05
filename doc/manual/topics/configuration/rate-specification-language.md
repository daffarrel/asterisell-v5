# Rate specification language

[main-rate-plan] are specified using a domain-specific language, describing the high-level details of a rating-plan in a compact and readable way.

``main-income-rate-plan`` is the name of the income [main-rate-plan], and ``main-cost-rate-plan`` is the name of the cost [main-rate-plan].

## Income rate example

```
rate {
  id: outgoing

  match-call-direction: outgoing

  rate {
    id: free-emergency-telephone-numbers
    match-telephone-number: 118,113,11X
    set-cost-for-minute: 0
  }

  rate {
    id: normal

    match-price-category: normal
    set-cost-on-call: 0.05

    external-rate {
      id: csv-1
      use: csv-1
      set-cost-for-minute: this
    }
  }

  rate {
    id: discounted

    match-price-category: discounted
    set-cost-on-call: 0.05

    external-rate {
      id: csv-discounted-2
      use: csv-discounted-2
      set-cost-for-minute: this
    }
  }
}

rate {
  id: free-incoming
  match-call-direction: incoming
  set-cost-for-minute: 0
}

rate {
  id: free-internal
  match-call-direction: internal
  set-cost-for-minute: 0
}
```

Rates are nested. So a CDR can be rated by ``outgoing/discounted/csv-discounted-2`` rate.

The best matching rate is selected. Matching criteria are usually telephone prefixes. 

## Full specification

```
rate {
  # "rate" is a rate that can be applied to a CDR, and that can calc its cost/income.


  id: [reference]
  # a short internal reference name, used also in debug sessions for
  # discovering which rate is applied to a CDR.

  #
  # Specify on which CDRs the rate is applicable
  #

  # All these matches are optionals.

  match-communication-channel: [list of communication channel types]
  # if the CDR match one of the channels in the list, this rate can be applied.
  # It can be used for calculating incomes, if the end-customer knows in advance
  # the used communication-channel.
  # Usually this is an internal information of the VoIP provider, so it is fair
  # using this only for calculating costs.

  match-vendor: [list of vendors]
  # if the CDR match one of the vendors in the list, this rate can be applied.
  # A vendor is a VoIP provider used from a reseller for routing the calls.
  # This can not be used (usually) for calculating incomes, because an end-customer
  # does not know the vendor used, and he is not responsible for this.
  # So it is usually used only for calculating costs.

  match-price-category: [list of price categories]
  # see notes on "Rates->Price Categories" section, on how to model hierarchical price categories.

  match-call-direction: [outgoing|incoming|internal|system]

  match-telephone-number: [list of telephone numbers]
  # The external telephone number:
  #   * the called telephone number for outgoing and internal calls,
  #   * the calling telephone number for incoming calls.
  #
  # Telephone numbers are expressed in the same format used for specifying internal extensions,
  # so something like "123,123X,123X*,123*,123\X\*",
  # where "X" stays for any character,
  #       "*" stays for zero or more characters.
  # "\\\\" is the quotation for the "\\" character.
  # "\," is the quotation for the "," character
  # " 123, 456" is parsed into extensions "123", and "456"
  # " 123, 4 5 6" is parsed into extensions "123", and "4 5 6"
  # "\ 123, \ 456" is parsed into extensions " 123", and " 456"
  #
  # NOTE: in case of a list of many telephone numbers, it is best using CSV files, and external-rate references.

  match-rating-code: [list of rating codes]
  # match if the external telephone number (maybe ported) is associated to an entry
  # in the telephone prefix table, with the specified rating-code.
  # In many cases a rating-code represent the telephone operator owning/responsible
  # for the external telephone number.
  # It is useful for assigning common codes to various telephone prefixes with a common property,
  # and then using (simpler) rules matching only the rating-code instead of all the telephone prefixes.
  #
  # This can be used for rating also incomes, because customers are informed of the called telephone number.

  match-peak-code: [list of peak codes]
  # match if the call time respect one of the listed peak-codes.
  # Peak-codes are defined in the "Params -> Holiday table".
  #
  # Peak codes that are one the opposite of the other can be specified defining only one of the two,
  # and using rates with an "else" part for matching the opposite peak-code.
  #
  # Peak-code can be used for rating also incomes, because customers should be informed of their rating plan,
  # and they know the call time.

  #
  # Specify how to calculate the cost of the call
  #

  # NOTE: Sets must be always specified after the match part.

  # These are the tranformations that can be applied on the
  # billable duration of a call.
  # The tranformations are applied in the specified order,
  # so also the order of specification is mandatory.
  # If a parameter is not specified, the default value is assumed.

  set-free-seconds: [number of seconds]
  # do not apply the cost-for-minute to these first seconds.
  # 0 is the default value.

  set-duration-discrete-increments: [number of seconds]
  # rate every specified seconds.
  # 0 is the default value.
  #
  # If the specified value is for example 3,
  # then a call with duration 0,1,2 is considered as 3 second,
  # a call with duration 3,4,5 is considered as 6 seconds, and so on.

  set-at-least-seconds: [number of seconds]
  # consider the call at least as the specified seconds

  # These are the tranformations that are applied to the cost of a call.
  # The tranformations are applied in the specified order,
  # so also the order of specification is mandatory.
  # If a parameter is not specified, the default value is assumed.

  set-cost-on-call: [1.0|imported|expected]
  # the initial cost of the call (default value is 0)
  # "imported" is used for CDRS imported from external sources, having already the cost/income calculated
  # "expected" is used for CDRS imported from external sources/providers, and force the usage of the expected cost field

  set-cost-for-minute: [1.0]
  # the cost of the call,
  # specified for every minute, otherwise the value is too low to specify,
  # but applied by default for every second of call.
  # (default value is 0)

  set-max-cost-of-call: [1.0]
  # apply this cost, if the calculated cost of the call is major than this

  set-min-cost-of-call: [1.0]
  # apply this cost, if the calculated cost of the call is less that this

  set-round-to-decimal-digits: [integer]
  # round the cost of the call to the specified digits.
  # For example 2.41, 2.44 became 2.4, and 2.45, 2.48 became 2.5, when rounding to the 1st decimal digit.
  # If left unspecified, use the maximum possible precision, without any rounding.

  set-ceil-to-decimal-digits: [integer]
  # ceil the cost of the call to the specified decimal digits.
  # For example both 2.41, 2.44, 2.48, became 2.5 when ceiling to the 1st decimal digit.
  # If left unspecified, use the maximum possible precision, without any rounding.
  # This operation is done after the round operation.
  # So it is possible round to 4 digit, and ceiling later to 3 digits.

  set-floor-to-decimal-digits: [integer]
  # floor the cost of the call to the specified decimal digits.
  # For example both 2.41, 2.44, 2.48, became 2.4, when flooring to the 1st decimal digit.
  # If left unspecified, use the maximum possible precision, without any rounding.
  # This operation is done after the ceil operation.

  rate {
    # A child rate, inherits all the sets and matches of its parent rate, and it can override them.
    # Rates can be arbitrary nested, in order to grouping rates by common characteristics.
    # For avoiding ratinng errors, if a rate has one ore more children, then one and only one of its children
    # must match the CDR with stronger priority, and it will be used for rating the CDR.
    # If there are two or more matching rates, then an error is signaled.
    # If no children match the CDR, then an error is signaled.

    id: [reference]
    # this can be a short id, because the complete rate reference name will
    # contain automatically also the parent id.
    # For example "root/outgoing/emergency-telephone-numbers" is a complete path of ids,
    # where the single id parts are "root" for the id of the initial root rate,
    # "outgoing" is the id of the first nested rate, and "emergency-telephone-numbers" is
    # the id of the final nested rate. If the final nested rate is applied to a CDR,
    # then the signaled applied rate is "root/outgoing/emergency-telephone-numbers".
    # So there can be repeated "id" names in nested rates, because only the full
    # path id must be unique.

    [continue with another rate specification]

  }

  rate {
    [another rate specification]
  }

  # A child rate is selected only if:
  # * the parent rate is applicable (so it inherits the matches of the parent)
  # * it is applicable
  # * there is no other children rate that is applicable using stronger matches on the match-telephone-number part
  # So it is always selected the children rate with the longest matching telephone number.

  # In case of two children rates matching a telephone number with the same strength, a rating error is signaled

  external-rate {
    # this is another child rate, but of type "external-rate", instead of generic "rate"
    #
    # An "external-rate" calls another rating method, specified in an external rate specification file,
    # typically a CSV file in some rating format associated to it.
    #
    # An external-rate can have no nested children rates.

    id: [reference]

    use: [rate-reference-name]
    # the name used in "Rates->Rates" form, for naming the external rate to call.
    # The format of the rate is specified in "Rates->Rates".
    # The external rate contains additional matches, and return calc params on the CDR.

    set-cost-on-call: [this|parent|specific-value]
    # Use "this" for using the value returned from the called external rate (usually a CSV file).
    # Use "parent" if the value is not in the CSV file, and the value of the parent rate must be used.
    # Indicate a specific value, if the value is not in the CSV file.

    set-cost-for-minute: [...]
    set-max-cost-of-call: [...]
    # NOTE: the same syntax applies to `set-cost-for-minute` and all other rating params
  }

}
```

## Rates with explicit priority

By default rates are selected according the longest matched telephone
prefix. But rates can have also an explicit priority, thanks to `else`
construct. 

```
rate {
  id: r1

  rate {
    id: r2
  } else {
    rate {
      id: r3
    }
  }
} else {
  rate {
    id: r4
  }
}
```

`r1/r3` is applied only if:

  - `r1` is applicable
  - `r1/r2` is not applicable
  - `r1/r3` is applicable

`r4` is applied only if:

  - `r1` and its children rates are not applicable
  - `r4` is applicable

So rate `r1` has implicitely more priority (is always preferred) respect
rate `r4`, and the same is true for `r1/r2`, against `r1/r3`.
