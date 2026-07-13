import asyncio

from app.core.roles import Roles
from sqlalchemy import select
from app.auth.security import get_password_hash
from app.database.session import SessionLocal
from app.models.customer import Customer

CUSTOMER ={
    "name":"test customer",
    "email": "customern@examcenter.com",
    "password": "customer123",
    "role": "customer"
}

def seed():
    db = SessionLocal()
    try:
        result = db.execute(select(Customer).where(Customer.email == CUSTOMER["email"]))

        customer = result.scalar_one_or_none()

        if customer:
            print("customer already exists")
            return 
        customer = Customer(full_name=CUSTOMER["full_name"],
                    email=CUSTOMER["email"],
                    password_hash=get_password_hash(CUSTOMER["password"]),
                    role=CUSTOMER["role"],
                    is_active=True)
        
        db.add(customer)
        db.commit()

        print("super Admin created successfully")
        print(f"email: {CUSTOMER['email']}")
        print(f"password: {CUSTOMER['password']}")
    finally:
        db.close()

if __name__ == "__main__":
    seed()














