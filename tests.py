import unittest
from unittest_data_provider import data_provider
from services import BarbaricLoggingInjector


class BarbaricLoggingInjectorTest(unittest.TestCase):
    pattern = lambda: (
        ('if ( true ) {', True),
    )

    @data_provider(pattern)
    def test_has_if(self, text, expected):
        service = BarbaricLoggingInjector()
        actual = service.has_if(text)
        self.assertEqual(expected, actual)
