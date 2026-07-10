from abc import ABC, abstractmethod


class AbstractUnitOfWork(ABC):

    payment_repository = None
    invoice_repository = None
    receipt_repository = None
    outbox_repository = None
    customer_repository = None
    school_repository = None

    @abstractmethod
    def commit(self):
        ...

    @abstractmethod
    def rollback(self):
        ...

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.rollback()