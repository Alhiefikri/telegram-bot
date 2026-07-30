from unittest.mock import MagicMock
import pytest
from src.camouproxy.netflix_loop import check_netflix_trial_offer, run_netflix_trial_loop


def test_check_netflix_trial_offer_found_rupee_zero():
    mock_page = MagicMock()
    mock_page.content.return_value = "<html><body><h1>Watch Netflix for ₹0 for the first month</h1></body></html>"
    mock_page.inner_text.return_value = "Watch Netflix for ₹0 for the first month"

    assert check_netflix_trial_offer(mock_page) is True


def test_check_netflix_trial_offer_found_free_trial():
    mock_page = MagicMock()
    mock_page.content.return_value = "<html><body><h1>Start Your Free Trial Today</h1></body></html>"
    mock_page.inner_text.return_value = "Start Your Free Trial Today"

    assert check_netflix_trial_offer(mock_page) is True


def test_check_netflix_trial_offer_found_0_rupees():
    mock_page = MagicMock()
    mock_page.content.return_value = "<html><body><h1>Pay 0 rupees first month</h1></body></html>"
    mock_page.inner_text.return_value = "Pay 0 rupees first month"

    assert check_netflix_trial_offer(mock_page) is True


def test_check_netflix_trial_offer_not_found_standard_pricing():
    mock_page = MagicMock()
    mock_page.content.return_value = "<html><body><h1>Unlimited movies, TV shows and more</h1><p>Starts at ₹149. Cancel anytime.</p></body></html>"
    mock_page.inner_text.return_value = "Unlimited movies, TV shows and more. Starts at ₹149. Cancel anytime."

    assert check_netflix_trial_offer(mock_page) is False


def test_check_netflix_trial_offer_handles_exceptions():
    mock_page = MagicMock()
    mock_page.content.side_effect = Exception("Page crashed")
    mock_page.inner_text.side_effect = Exception("Selector error")

    assert check_netflix_trial_offer(mock_page) is False
