#!/usr/bin/env python3
"""测试脚本"""

import sys
import json

def main():
    print("Hello from Python script!")
    print("Arguments:", sys.argv[1:])
    
    result = {
        "status": "success",
        "message": "Script executed successfully",
        "args": sys.argv[1:]
    }
    
    print(json.dumps(result))

if __name__ == "__main__":
    main()
