# public/js/overview.js

## makePatterns

The makePatterns function takes a string as input and returns an array of regex patterns. For the input "Hello World" the output is:

	[/\bHello.*\b/i, /\bWorld.*\b/i]

Terms separated by spaces are split into different regexes. Each regex matches a string where the term can be found at the start of a word that exists between two word boundaries. The regexes are case insensitive (i).

Note that all non-word characters are considered word boundaries. For the input "tue.nl" the output is:

	[/\btue.nl.*\b/i]

The regex matches the the string "http://adfs.tue.nl/adfs/services/trust" since the characters "." and the "/", respectively before and after "tue.nl", are considered word boundaries.