"""Store-Bot adapter over the P20 Customer API credential owner."""
from django.db import transaction

from ..checkout.db import Database
from ..customer_api import credentials
from ..customer_api.admission import Admission, ApiFailure, check_availability


def _failure(code='not_authorized', message='A current private Store Bot conversation is required.'):
    return ApiFailure(403, code, message)


class ApiActions:
    def __init__(self, admission=None, using='default'):
        self._admission = admission
        self.db = Database(using)
        self.using = using

    # -- availability -------------------------------------------------

    def enabled(self):
        try:
            check_availability()
            return True
        except ApiFailure as error:
            if error.code == 'api_maintenance':
                return False
            raise

    def admission(self):
        if self._admission is None:
            self._admission = Admission()
        return self._admission

    # -- root authority ----------------------------------------------

    def _with_root(self, customer, chat_id, message_id, operation):
        with transaction.atomic(using=self.using):
            # Match the PHP lock order: Customer first, then the current root.
            self.db.one("SELECT id FROM public.customers WHERE id=%s FOR UPDATE",
                        (customer['id'],))
            row = self.db.one(
                """SELECT c.telegram_id,r.message_id
                   FROM django_candidate.navigation_roots r
                   JOIN public.customers c ON c.id=r.customer_id
                   WHERE r.customer_id=%s AND r.chat_id=%s
                   FOR SHARE OF r""",
                (customer['id'], str(chat_id)))
            if (row is None or row['message_id'] != message_id
                    or str(row['telegram_id']) != str(chat_id)):
                raise _failure()
            return operation()

    def _require_private_actor(self, customer, chat_id, message_id):
        if (type(message_id) is not int or message_id < 1
                or str(customer.get('telegram_id')) != str(chat_id)
                or not self.enabled()):
            raise _failure('not_authorized',
                           'A current private Store Bot conversation is required.')

    # -- operations ---------------------------------------------------

    def summary(self, customer, chat_id, message_id):
        self._require_private_actor(customer, chat_id, message_id)
        self._with_root(customer, chat_id, message_id, lambda: None)
        return credentials.summary(customer['id'])

    def generate(self, customer, chat_id, message_id):
        self._require_private_actor(customer, chat_id, message_id)
        return self._with_root(
            customer, chat_id, message_id,
            lambda: (self.admission().key_operation(customer['id']),
                     credentials.generate(customer['id']))[1])

    def prepare_rotation(self, customer, chat_id, message_id):
        self._require_private_actor(customer, chat_id, message_id)
        return self._with_root(
            customer, chat_id, message_id,
            lambda: (self.admission().key_operation(customer['id'], confirmation=True),
                     credentials.prepare_rotation(customer['id'], message_id))[1])

    def prepare_disable(self, customer, chat_id, message_id):
        self._require_private_actor(customer, chat_id, message_id)
        return self._with_root(
            customer, chat_id, message_id,
            lambda: (self.admission().key_operation(customer['id'], disable=True),
                     credentials.prepare_disable(customer['id'], message_id))[1])

    def rotate(self, customer, chat_id, message_id, confirmation):
        self._require_private_actor(customer, chat_id, message_id)
        return self._with_root(
            customer, chat_id, message_id,
            lambda: (self.admission().key_operation(customer['id']),
                     credentials.rotate(customer['id'], message_id, confirmation))[1])

    def disable(self, customer, chat_id, message_id, confirmation):
        self._require_private_actor(customer, chat_id, message_id)
        return self._with_root(
            customer, chat_id, message_id,
            lambda: (self.admission().key_operation(customer['id'], disable=True),
                     credentials.disable_confirmed(customer['id'], message_id, confirmation))[1])

    def preference(self, customer, chat_id, message_id, preference):
        self._require_private_actor(customer, chat_id, message_id)
        return self._with_root(
            customer, chat_id, message_id,
            lambda: credentials.set_preference(customer['id'], preference))
